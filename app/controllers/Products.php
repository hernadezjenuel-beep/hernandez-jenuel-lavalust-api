<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Products extends Controller
{
    private const FIELDS = ['name', 'description', 'price', 'stock'];
    private $product_schema;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->api->require_jwt();
    }

    public function index()
    {
        $products = $this->db->raw(
            'SELECT ' . $this->product_select_columns() . '
             FROM products ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond(['data' => $products]);
    }

    public function show($id)
    {
        $product = $this->find_product($id);
        if (!$product) {
            $this->api->respond_error('Product not found.', 404);
        }

        $this->api->respond(['data' => $product]);
    }

    public function create()
    {
        $body = $this->read_json_body();
        $data = $this->validated_product($body, true);

        $this->db->raw(
            'INSERT INTO products (' . $this->product_schema()['name'] . ', description, price, '
                . $this->product_schema()['stock'] . ', created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$data['name'], $data['description'], $data['price'], $data['stock']]
        );

        $id = $this->db->last_id();
        header('Location: /api/products/' . $id);
        $this->api->respond(['data' => $this->find_product($id)], 201);
    }

    public function update($id)
    {
        $body = $this->read_json_body();
        $data = $this->validated_product($body, false);
        if (!$data) {
            $this->api->respond_error('Provide at least one product field to update.', 422);
        }

        if (!$this->find_product($id)) {
            $this->api->respond_error('Product not found.', 404);
        }

        $assignments = [];
        $values = [];
        foreach ($data as $field => $value) {
            $column = $field === 'name' || $field === 'stock'
                ? $this->product_schema()[$field]
                : $field;
            $assignments[] = "{$column} = ?";
            $values[] = $value;
        }
        if ($this->product_schema()['has_updated_at']) {
            $assignments[] = 'updated_at = NOW()';
        }
        $values[] = (int) $id;

        $this->db->raw(
            'UPDATE products SET ' . implode(', ', $assignments) . ' WHERE id = ?',
            $values
        );

        $this->api->respond(['data' => $this->find_product($id)]);
    }

    public function delete($id)
    {
        if (!$this->find_product($id)) {
            $this->api->respond_error('Product not found.', 404);
        }

        $this->db->raw('DELETE FROM products WHERE id = ?', [(int) $id]);
        $this->api->respond(['message' => 'Product deleted successfully.']);
    }

    private function read_json_body()
    {
        $body = $this->request->json();
        if (!is_array($body) || array_is_list($body)) {
            $this->api->respond_error('A JSON object is required.', 400);
        }

        return $body;
    }

    private function validated_product(array $body, bool $required)
    {
        $unknown = array_diff(array_keys($body), self::FIELDS);
        if ($unknown) {
            $this->api->respond_error('Unknown product field: ' . reset($unknown), 422);
        }

        if ($required && array_diff(self::FIELDS, array_keys($body))) {
            $this->api->respond_error('name, description, price, and stock are required.', 422);
        }

        $data = [];
        if (array_key_exists('name', $body)) {
            if (!is_string($body['name']) || trim($body['name']) === '' || strlen(trim($body['name'])) > 150) {
                $this->api->respond_error('name must be a non-empty string of at most 150 characters.', 422);
            }
            $data['name'] = trim($body['name']);
        }

        if (array_key_exists('description', $body)) {
            if ($body['description'] !== null && !is_string($body['description'])) {
                $this->api->respond_error('description must be a string or null.', 422);
            }
            $data['description'] = $body['description'];
        }

        if (array_key_exists('price', $body)) {
            if ((!is_string($body['price']) && !is_int($body['price']) && !is_float($body['price']))
                || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', (string) $body['price'])) {
                $this->api->respond_error('price must be a non-negative number with at most 10 digits and 2 decimal places.', 422);
            }
            $price = number_format((float) $body['price'], 2, '.', '');
            if (strlen(str_replace('.', '', $price)) > 10) {
                $this->api->respond_error('price exceeds the supported range.', 422);
            }
            $data['price'] = $price;
        }

        if (array_key_exists('stock', $body)) {
            if (!is_int($body['stock']) && !(is_string($body['stock']) && ctype_digit($body['stock']))) {
                $this->api->respond_error('stock must be a non-negative integer.', 422);
            }
            if ((float) $body['stock'] > 2147483647) {
                $this->api->respond_error('stock exceeds the supported range.', 422);
            }
            $data['stock'] = (int) $body['stock'];
        }

        return $data;
    }

    private function find_product($id)
    {
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->api->respond_error('Product ID must be a positive integer.', 400);
        }

        $stmt = $this->db->raw(
            'SELECT ' . $this->product_select_columns() . '
             FROM products WHERE id = ? LIMIT 1',
            [(int) $id]
        );

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function product_select_columns()
    {
        $schema = $this->product_schema();
        $updated_at = $schema['has_updated_at'] ? 'updated_at' : 'NULL AS updated_at';

        return 'id, ' . $schema['name'] . ' AS name, description, price, '
            . $schema['stock'] . ' AS stock, created_at, ' . $updated_at;
    }

    private function product_schema()
    {
        if ($this->product_schema !== null) {
            return $this->product_schema;
        }

        $columns = $this->db->raw('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        $this->product_schema = [
            'name' => in_array('product_name', $columns, true) ? 'product_name' : 'name',
            'stock' => in_array('quantity', $columns, true) ? 'quantity' : 'stock',
            'has_updated_at' => in_array('updated_at', $columns, true),
        ];

        if (!in_array($this->product_schema['name'], $columns, true)
            || !in_array($this->product_schema['stock'], $columns, true)) {
            throw new RuntimeException('The products table is missing the product name or stock column.');
        }

        return $this->product_schema;
    }
}
