<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
    }

    public function register()
    {
        $body = $this->request->json();
        if (!is_array($body) || array_is_list($body)) {
            $this->api->respond_error('A JSON object is required.', 400);
        }

        $username = isset($body['username']) && is_string($body['username'])
            ? trim($body['username'])
            : '';
        $email = isset($body['email']) && is_string($body['email'])
            ? trim($body['email'])
            : '';
        $password = $body['password'] ?? null;

        if (!preg_match('/^[A-Za-z0-9_]{3,100}$/', $username)) {
            $this->api->respond_error('Username must be 3-100 characters and contain only letters, numbers, or underscores.', 422);
        }

        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('A valid email address is required.', 422);
        }

        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
            $this->api->respond_error('Password must be between 8 and 72 bytes.', 422);
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
            "INSERT INTO users (username, email, password, role, is_active, created_at)
             VALUES (?, ?, ?, 'user', 1, NOW())",
            [$username, $email, $password_hash]
        );

        $user = [
            'id' => $this->db->last_id(),
            'role' => 'user',
        ];
        $tokens = $this->api->issue_tokens([
            'id' => $user['id'],
            'role' => $user['role'],
            'scopes' => ['read'],
        ]);

        $this->api->respond([
            'user' => [
                'id' => $user['id'],
                'username' => $username,
                'email' => $email,
                'role' => $user['role'],
            ],
            'tokens' => $tokens,
        ], 201);
    }

    public function login()
    {
        $body = $this->request->json();
        if (!is_array($body) || array_is_list($body)) {
            $this->api->respond_error('A JSON object is required.', 400);
        }

        $username = isset($body['username']) && is_string($body['username'])
            ? trim($body['username'])
            : '';
        $password = $body['password'] ?? null;

        if ($username === '' || !is_string($password) || $password === '') {
            $this->api->respond_error('Username and password are required.', 422);
        }

        $this->api->rate_limit();

        $stmt = $this->db->raw(
            'SELECT id, username, email, password, role, is_active
             FROM users
             WHERE username = ?
             LIMIT 1',
            [$username]
        );
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !(bool) $user['is_active'] || !password_verify($password, $user['password'])) {
            $this->api->respond_error('Invalid credentials.', 401);
        }

        $tokens = $this->api->issue_tokens([
            'id' => $user['id'],
            'role' => $user['role'],
            'scopes' => ['read'],
        ]);

        $this->api->respond([
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
            'tokens' => $tokens,
        ]);
    }
}
