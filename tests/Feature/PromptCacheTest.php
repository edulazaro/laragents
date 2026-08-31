<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * The discount, and whether it reaches your accounting.
 *
 * OpenAI caches long prefixes by itself and bills the reused part cheaper, with nothing
 * to send and nothing to opt into. That happens whether or not this client notices. What
 * these tests pin is the part that is ours: recording what the call actually cost rather
 * than the raw prompt count, because the gap between the two is an allowance that runs
 * out faster than the invoice says it should, and it is invisible from both ends.
 */
class PromptCacheTest extends TestCase
{
    private function responder(int $prompt, ?int $cached, int $completion = 200): void
    {
        $usage = ['prompt_tokens' => $prompt, 'completion_tokens' => $completion];

        if ($cached !== null) {
            $usage['prompt_tokens_details'] = ['cached_tokens' => $cached];
        }

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => $usage,
        ], 200)]);
    }

    private function preguntar()
    {
        return $this->app->make(ChatClient::class)->chat(
            messages: [['role' => 'user', 'content' => 'hola']],
            model: 'gpt-4.1-mini',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('laragents.openai.api_key', 'sk-test');
        $this->app->bind(ChatClient::class, OpenAiClient::class);
    }

    public function test_a_cached_prefix_is_billed_at_the_configured_share(): void
    {
        config()->set('laragents.openai.cache_read_weight', 0.5);

        // 10.000 de entrada, 8.000 de ellos reutilizados: 2.000 a precio entero más
        // 8.000 a mitad son 6.000 efectivos.
        $this->responder(prompt: 10_000, cached: 8_000);

        $response = $this->preguntar();

        $this->assertSame(6_000, $response->inputTokens);
        $this->assertSame(8_000, $response->cacheReadTokens);
    }

    /**
     * Sin caché, nada cambia. Es la mitad del trato: el arreglo no puede mover la
     * factura de las llamadas que no se cachean, que son la mayoría de las primeras.
     */
    public function test_an_uncached_call_is_unchanged(): void
    {
        $this->responder(prompt: 10_000, cached: 0);

        $this->assertSame(10_000, $this->preguntar()->inputTokens);
    }

    /**
     * Y un proveedor que no informe de la caché tampoco.
     *
     * El campo es relativamente nuevo y no todos los endpoints compatibles lo devuelven.
     * Ausente tiene que leerse como "no hubo caché", nunca como cero tokens de entrada.
     */
    public function test_a_response_without_the_field_is_unchanged(): void
    {
        $this->responder(prompt: 10_000, cached: null);

        $response = $this->preguntar();

        $this->assertSame(10_000, $response->inputTokens);
        $this->assertSame(0, $response->cacheReadTokens);
    }

    /**
     * Un recuento de caché mayor que el total no puede acabar en entrada negativa.
     *
     * Los dos números vienen del proveedor y solo uno está documentado como subconjunto
     * del otro. Sin el recorte, una respuesta rara acreditaría créditos a la cuenta.
     */
    public function test_a_cached_count_above_the_total_cannot_credit_the_account(): void
    {
        config()->set('laragents.openai.cache_read_weight', 0.5);
        $this->responder(prompt: 1_000, cached: 5_000);

        $this->assertSame(500, $this->preguntar()->inputTokens);
    }

    /**
     * Y un peso mal escrito tampoco.
     */
    public function test_a_weight_outside_zero_to_one_is_clamped(): void
    {
        $this->responder(prompt: 10_000, cached: 10_000);

        config()->set('laragents.openai.cache_read_weight', -3);
        $this->assertSame(0, $this->preguntar()->inputTokens);

        config()->set('laragents.openai.cache_read_weight', 9);
        $this->assertSame(10_000, $this->preguntar()->inputTokens);
    }

    /**
     * El peso por defecto se equivoca HACIA ARRIBA a propósito.
     *
     * Pasarse cobra un poco por encima de la factura, que es un error de redondeo.
     * Quedarse corto cobra por debajo de lo que pagas, y eso solo se ve en la factura
     * del proveedor cuando ya lleva meses pasando.
     */
    public function test_the_default_weight_errs_high(): void
    {
        $this->responder(prompt: 10_000, cached: 10_000);

        $this->assertGreaterThanOrEqual(5_000, $this->preguntar()->inputTokens);
    }
}
