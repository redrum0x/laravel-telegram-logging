<?php

namespace redrum0x\TelegramLogger;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
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
    private array $ignoreMessages = [];
    private array $ignoreContext = [];

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

        $ignoreContext = config('telegram-logger.ignore_context');
        if (!empty($ignoreContext)) {
            $this->ignoreContext = explode(',', $ignoreContext);
        }

        $this->telegramService = new TelegramService(config('telegram-logger.bot_token'),
            config('telegram-logger.chat_id'), config('telegram-logger.base_url'));
    }

    /**
     * Send log text to Telegram
     *
     * @param LogRecord $record
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        if (empty(config('telegram-logger.chat_id'))) {
            return;
        }

        $data = $this->buildLogData($record);

        if (!empty($this->ignoreMessages) && !empty($data['Message'])) {
            foreach ($this->ignoreMessages as $message) {
                if (str_starts_with($data['Message'], $message)) {
                    return;
                }
            }
        }

        if (!empty($this->ignoreContext) && !empty($data['Context'])) {
            foreach ($this->ignoreContext as $message) {
                if (str_contains($data['Context'], $message)) {
                    return;
                }
            }
        }

        try {
            $this->telegramService->sendMessage($this->formatLogText($data));
        } catch (\Exception $e) {
        }
    }

    /**
     * @param LogRecord $record
     * @return array
     */
    private function buildLogData(LogRecord $record): array
    {
        $data = [];

        $data['Application'] = $this->applicationName;
        $data['Log Level'] = $record->level->getName();
        $data['User id'] = Auth::user()?->getAuthIdentifier() ?? '-';
        $data['URL'] = request()->url();

        if ($this->logRequestData) {
            $data['Request query'] = json_encode(Request::query(), JSON_UNESCAPED_UNICODE);
            $data['Request body'] = json_encode(Request::post(), JSON_UNESCAPED_UNICODE);
        }


        $data['IP'] = $this->getIp();
        $data['ctx'] = self::getCtxByException();
        $data['Message'] = '<pre>' . ($record->message ?? '') . '</pre>';

        if (!empty($record->extra)) {
            $data['Extra'] .= '<code>' . json_encode($record->extra, JSON_UNESCAPED_UNICODE) . '</code>';
        }


        if ($this->logTraceException && isset($record->context['exception']) && $record->context['exception'] instanceof \Exception) {
            $exception = $record->context['exception'];
            /** @var \Exception $exception */

            $data['Trace exception'] = $exception->getTraceAsString();
            $record->extra['context']['exception'] = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
            ];
        }

        if (!empty($record->context)) {
            $data['Context'] = '<code>' . json_encode($record->context, JSON_UNESCAPED_UNICODE) . '</code>';
        }

        return $data;
    }

    /**
     * Format log text to send
     *
     * @return string
     * @var array $record
     */
    protected function formatLogText(array $data): string
    {
        $traceKey = 'Trace exception';
        $logText = '';
        foreach ($data as $key => $item) {
            $name = '<b>' . $key . '</b>: ';
            if ($key === $traceKey) {
                continue;


            }
            $logText .= $name . $item . PHP_EOL;
        }

        if (isset($data[$traceKey])) {
            $maxSize = 4090 - strlen($logText . '<b>' . $traceKey . '</b>: ');
            $traceItem = $maxSize > 0 ? mb_substr($data[$traceKey], 0, $maxSize) : $data[$traceKey];
            $logText .= '<b>' . $traceKey . '</b>: ' . $traceItem . PHP_EOL;
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

    /**
     * @return string
     */
    public function getIp(): string
    {
        $userIp = $_SERVER['HTTP_CLIENT_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $userIp = str_replace(' ', '', $userIp);
        $userIp = explode(',', $userIp);

        return $userIp[0];
    }
}
