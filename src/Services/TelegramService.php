<?php

namespace redrum0x\TelegramLogger\Services;

/**
 * Class TelegramService
 *
 * @package redrum0x\TelegramLogger
 */
class TelegramService
{

    /**
     * Telegram base API URL
     *
     * @var string
     */
    private $telegramApiBaseUrl;

    /**
     * Endpoint of send message in Telegram API
     *
     * @var string
     */
    private $telegramApiSendMessageEndpoint;

    /**
     * Token of your Telegram Bot that will send the messages
     *
     * @var string
     */
    private $telegramBotToken;

    /**
     * ID of your Telegram group that will receive the messages.
     *
     * @var string
     */
    private $telegramChatId;

    /**
     * Proxy URL for Telegram API requests (e.g. 'socks5://user:pass@host:port')
     *
     * @var string|null
     */
    private $proxy;

    public function __construct(string $telegramBotToken, string $telegramChatId, string $telegramApiBaseUrl, ?string $proxy = null)
    {
        $this->telegramApiBaseUrl = $telegramApiBaseUrl . 'bot';
        $this->telegramApiSendMessageEndpoint = 'sendMessage';
        $this->telegramBotToken = $telegramBotToken;
        $this->telegramChatId = $telegramChatId;
        $this->proxy = $proxy;
    }

    public function sendMessage(string $messageText)
    {
        $telegramApiSendMessageFullUrl = $this->telegramApiBaseUrl . $this->telegramBotToken . '/' . $this->telegramApiSendMessageEndpoint;
        $requestQueryData = $this->prepareRequestQuery($messageText);

        try {
            $responseStatusCode = $this->getResponseStatusCode($telegramApiSendMessageFullUrl . '?' . $requestQueryData);

            return $this->returnResponseOfApiByStatusCode($responseStatusCode);
        } catch (\Exception $exception) {}

        return null;
    }

    private function prepareRequestQuery(string $messageText)
    {
        return http_build_query([
            'text' => $messageText,
            'chat_id' => $this->telegramChatId,
            'parse_mode' => 'html',
        ]);
    }

    private function returnResponseOfApiByStatusCode($responseStatusCode)
    {
        $responseMessages = [
            '200' => 'Message has been sent.',
            '400' => 'Chat ID is not valid.',
            '401' => 'Bot Token is not valid.',
            '404' => 'Bot Token is not valid.',
        ];

        return $responseMessages[$responseStatusCode] ?? null;
    }

    private function getResponseStatusCode(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if (!empty($this->proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);

            if (str_starts_with($this->proxy, 'socks5://')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            } elseif (str_starts_with($this->proxy, 'socks4://')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
            } else {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
        }

        curl_exec($ch);
        $statusCode = (string) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $statusCode;
    }
}
