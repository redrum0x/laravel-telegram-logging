<?php

namespace redrum0x\TelegramLogger;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use redrum0x\TelegramLogger\Services\TelegramService;

/**
 * Class TelegramHandler
 *
 * @package redrum0x\TelegramLogger
 */
class TelegramLoggerHandler extends AbstractProcessingHandler
{

    /**
     * Application name
     *
     * @var string
     */
    private string $applicationName;

    /**
     * Application environment
     *
     * @var string
     */
    private string $applicationEnvironment;

    /**
     * Instance of TelegramService
     *
     * @var TelegramService
     */
    private $telegramService;

    private $logRequestData = false;
    private $logTraceException = false;
    private $ignoreMessages = [];

    /**
     * TelegramHandler constructor.
     *
     * @param string $logLevel
     */
    public function __construct(string $logLevel)
    {
        $monologLevel = Logger::toMonologLevel($logLevel);
        parent::__construct($monologLevel, true);

        $this->applicationName = config('app.name');
        $this->applicationEnvironment = config('app.env');
        $this->logRequestData = config('telegram-logger.log_request_data');
        $this->logTraceException = config('telegram-logger.log_trace_exception');
        $ignoreMessages = config('telegram-logger.ignore_messages');
        if (!empty($ignoreMessages)) {
            $this->ignoreMessages = explode(',', $ignoreMessages);
        }

        $this->telegramService = new TelegramService(config('telegram-logger.bot_token'),
            config('telegram-logger.chat_id'), config('telegram-logger.base_url'));
    }

    /**
     * Send log text to Telegram
     *
     * @param array $record
     * @return void
     */
    protected function write(array $record): void
    {
        if (!empty($this->ignoreMessages) && !empty($record['message'])) {
            foreach ($this->ignoreMessages as $message) {
                if (str_starts_with($record['message'], $message)) {
                    return;

                }
            }
        }
        $this->telegramService->sendMessage($this->formatLogText($record));
    }

    /**
     * Formart log text to send
     *
     * @return string
     * @var array $record
     */
    protected function formatLogText(array $record): string
    {
        $data = [];

        $data['Application'] = $this->applicationName;
        $data['Log Level'] = $record['level_name'];
        $data['User id'] = Auth::user()?->getAuthIdentifier() ?? '-';
        $data['URL'] = request()->url();

        if ($this->logRequestData) {
            $data['Request query'] = json_encode(Request::query());
            $data['Request body'] = json_encode(Request::post());
        }


        $data['IP'] = request()->ip();
        $data['ctx'] = self::getCtxByException();
        $data['Message'] = '<pre>' . ($record['message'] ?? '') . '</pre>';

        if (!empty($record['extra'])) {
            $data['Extra'] .= '<code>' . json_encode($record['extra']) . '</code>';
        }


        if ($this->logTraceException && isset($record['context']['exception']) && $record['context']['exception'] instanceof \Exception) {
            $exception = $record['context']['exception'];
            /** @var \Exception $exception */

            $data['Trace exception'] = $exception->getTraceAsString();
        }

        $logText = '';
        foreach ($data as $key => $item) {
            $name = '<b>' . $key . '</b>: ';
            if ($key === 'Trace exception') {
                $maxSize = 4090 - strlen($logText . $name);
                if ($maxSize > 0) {
                    $item = mb_substr($item, 0, $maxSize);
                }

            }
            $logText .= $name . $item . PHP_EOL;
        }

        return $logText;
    }


    /**
     * @return string
     */
    private function getCtxByException(): string
    {
        $trace = (new \Exception())->getTrace();

        foreach ($trace as $idx => $item) {
            if (in_array(($item['function'] ?? null), ['error', 'info', 'warning', 'critical'])) {
                $callerFunc = $trace[($idx + 3)]['function'] ?? '{UNKNOWN_FUNCTION}';
                return $trace[($idx + 2)]['file'] . '::' . $callerFunc . ':' . $trace[($idx + 2)]['line'];
            }
        }
        return '';
    }
}