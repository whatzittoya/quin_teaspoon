<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AuthController
{
    public function loginPage(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['user_name'])) {
            return $response->withHeader('Location', '/home')->withStatus(302);
        }
        $view = Twig::fromRequest($request);
        return $view->render($response, 'login.html.twig', [
            'error' => $_SESSION['login_error'] ?? null,
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $pin = trim($data['pin'] ?? '');

        if ($name === '' || $pin === '') {
            $_SESSION['login_error'] = 'Please fill in all fields.';
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $db = $this->db($request);
        $stmt = $db->prepare('SELECT name, pin FROM tbl_employees WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $name]);
        $user = $stmt->fetch();

        if ($user && $user['pin'] === $pin) {
            unset($_SESSION['login_error']);
            $_SESSION['user_name'] = $user['name'];
            return $response->withHeader('Location', '/home')->withStatus(302);
        }

        $_SESSION['login_error'] = 'Invalid name or PIN.';
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        session_destroy();
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    private function db(Request $request): \PDO
    {
        return $request->getAttribute('container')->get('db');
    }
}
