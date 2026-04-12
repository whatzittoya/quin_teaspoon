<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SchedulerController
{
    private function getEnabledFile(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scheduler' . DIRECTORY_SEPARATOR . '.enabled';
    }

    public function status(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $enabled = file_exists($this->getEnabledFile());

        // Read last few lines of log
        $logFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scheduler' . DIRECTORY_SEPARATOR . 'upload.log';
        $lastLog = '';
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);
            $lastLog = implode("\n", array_slice($lines, -10));
        }

        return $this->json($response, [
            'enabled' => $enabled,
            'last_log' => $lastLog,
        ]);
    }

    public function toggle(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $file = $this->getEnabledFile();
        $dir = dirname($file);

        // Ensure scheduler directory exists
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $enabled = file_exists($file);

        if ($enabled) {
            if (!@unlink($file)) {
                return $this->json($response, [
                    'enabled' => true,
                    'error' => 'Failed to delete .enabled file: ' . $file,
                ]);
            }
            $newState = false;
        } else {
            if (file_put_contents($file, 'enabled') === false) {
                return $this->json($response, [
                    'enabled' => false,
                    'error' => 'Failed to create .enabled file: ' . $file,
                ]);
            }
            $newState = true;
        }

        return $this->json($response, [
            'enabled' => $newState,
            'message' => $newState ? 'Scheduler enabled' : 'Scheduler paused',
        ]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
