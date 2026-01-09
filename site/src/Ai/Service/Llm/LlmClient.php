<?php

declare(strict_types=1);

namespace App\Ai\Service\Llm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LlmClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(string:LLM_API_URL)%')]
        private readonly string $apiUrl,
        #[Autowire('%env(string:LLM_API_KEY)%')]
        private readonly string $apiKey,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function chat(array $messages, ?LlmOptions $options = null): LlmResponse
    {
        $options ??= LlmOptions::forFinancialAssistant();

        $response = $this->httpClient->request('POST', $this->apiUrl, [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->apiKey),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $options->model,
                'temperature' => $options->temperature,
                'max_tokens' => $options->maxTokens,
                'messages' => $messages,
            ],
        ]);

        $payload = $response->toArray(false);
        $content = (string) ($payload['choices'][0]['message']['content'] ?? '');

        return new LlmResponse($content, $payload);
    }
}
