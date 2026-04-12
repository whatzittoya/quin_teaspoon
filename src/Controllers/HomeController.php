<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HomeController
{
    public function index(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $response->withHeader('Location', $request->getAttribute('base_path') . '/')->withStatus(302);
        }
        $view = Twig::fromRequest($request);
        return $view->render($response, 'home.html.twig', [
            'name' => $_SESSION['user_name'],
            'today' => date('Y-m-d'),
        ]);
    }
}
