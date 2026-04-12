<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SchedulerController
{
    private const TASK_NAME = 'QuinosSalesUpload';

    private function getSchedulerDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scheduler';
    }

    private function getBatPath(string $name): string
    {
        return $this->getSchedulerDir() . DIRECTORY_SEPARATOR . $name;
    }

    public function status(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        // Check if task exists and is enabled via schtasks
        $enabled = false;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = [];
            exec('schtasks /Query /TN "' . self::TASK_NAME . '" /FO CSV /NH 2>&1', $output, $exitCode);
            if ($exitCode === 0 && !empty($output[0])) {
                // CSV format: "TaskName","Next Run Time","Status"
                $enabled = stripos($output[0], 'Disabled') === false;
            }
        }

        // Read last few lines of log
        $logFile = $this->getSchedulerDir() . DIRECTORY_SEPARATOR . 'upload.log';
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

        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return $this->json($response, ['error' => 'Only supported on Windows'], 400);
        }

        // Check current state
        $output = [];
        exec('schtasks /Query /TN "' . self::TASK_NAME . '" /FO CSV /NH 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            // Task doesn't exist — create it
            $batPath = $this->getBatPath('run.bat');
            $cmd = 'schtasks /Create /TN "' . self::TASK_NAME . '" /TR "\"' . $batPath . '\"" /SC DAILY /ST 22:00 /F 2>&1';
            $createOutput = [];
            exec($cmd, $createOutput, $createExit);

            if ($createExit !== 0) {
                return $this->json($response, [
                    'enabled' => false,
                    'error' => 'Failed to create task: ' . implode(' ', $createOutput),
                ]);
            }
            return $this->json($response, ['enabled' => true, 'message' => 'Scheduler created and enabled']);
        }

        // Task exists — toggle enable/disable
        $isEnabled = stripos($output[0] ?? '', 'Disabled') === false;

        if ($isEnabled) {
            exec('schtasks /Change /TN "' . self::TASK_NAME . '" /DISABLE 2>&1', $out, $code);
            $newState = false;
        } else {
            exec('schtasks /Change /TN "' . self::TASK_NAME . '" /ENABLE 2>&1', $out, $code);
            $newState = true;
        }

        if ($code !== 0) {
            return $this->json($response, [
                'enabled' => $isEnabled,
                'error' => 'Failed to toggle task: ' . implode(' ', $out ?? []),
            ]);
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
