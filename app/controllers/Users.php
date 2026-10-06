<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Users extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
    }

    public function create()
    {
        $actor = $this->api->require_jwt();
        if (($actor['role'] ?? null) !== 'admin') {
            $this->api->respond_error('Administrator access is required.', 403);
        }

        $body = $this->request->json();
        if (!is_array($body) || array_is_list($body)) {
            $this->api->respond_error('A JSON object is required.', 400);
        }

        $unknown = array_diff(array_keys($body), ['username', 'email', 'password', 'role']);
        if ($unknown) {
            $this->api->respond_error('Unknown user field: ' . reset($unknown), 422);
        }

        $username = isset($body['username']) && is_string($body['username'])
            ? trim($body['username'])
            : '';
        $email = isset($body['email']) && is_string($body['email'])
            ? trim($body['email'])
            : '';
        $password = $body['password'] ?? null;
        $role = $body['role'] ?? 'user';

        if (!preg_match('/^[A-Za-z0-9_]{3,100}$/', $username)) {
            $this->api->respond_error('Username must be 3-100 characters and contain only letters, numbers, or underscores.', 422);
        }

        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('A valid email address is required.', 422);
        }

        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
            $this->api->respond_error('Password must be between 8 and 72 bytes.', 422);
        }

        if (!is_string($role) || !in_array($role, ['user', 'admin'], true)) {
            $this->api->respond_error('role must be either "user" or "admin".', 422);
        }

        $this->api->rate_limit();

        $existing = $this->db->raw(
            'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$username, $email]
        )->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $this->api->respond_error('Username or email is already registered.', 409);
        }

        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        if ($password_hash === false) {
            throw new RuntimeException('Could not securely hash the password.');
        }

        $this->db->raw(
            'INSERT INTO users (username, email, password, role, is_active, created_at)
             VALUES (?, ?, ?, ?, 1, NOW())',
            [$username, $email, $password_hash, $role]
        );

        $this->api->respond([
            'data' => [
                'id' => $this->db->last_id(),
                'username' => $username,
                'email' => $email,
                'role' => $role,
            ],
        ], 201);
    }
}
