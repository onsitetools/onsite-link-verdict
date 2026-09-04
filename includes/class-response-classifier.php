<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Traduz o resultado de um pedido HTTP num veredito.
 *
 * A regra que manda: só se acusa um link de PARTIDO quando há motivo inequívoco.
 * Na dúvida, WARNING (volta-se a tentar) ou UNVERIFIABLE (mostra-se a parte, sem alarme).
 */
class Response_Classifier {

    /** Vivo e bem. */
    const OK = 'ok';

    /** Morto sem margem para dúvida. Só 404 e 410 — ver a nota sobre erros de ligação. */
    const BROKEN = 'broken';

    /** Pode ser passageiro. Volta-se a tentar antes de acusar. */
    const WARNING = 'warning';

    /** O servidor recusa-se a falar com robôs. Não é problema do link. */
    const UNVERIFIABLE = 'unverifiable';

    /** Códigos que provam que a página deixou de existir. */
    private static $definitivamente_partidos = array( 404, 410 );

    /** Códigos em que o servidor nos barra a nós, não ao visitante. */
    private static $nao_verificaveis = array( 401, 403, 405, 406, 429, 451, 999 );

    /**
     * Cabeçalhos que denunciam um muro de robôs, seja qual for o código.
     *
     * Um servidor que recusa pedidos automáticos não está a dizer que a página
     * morreu — está a dizer que não fala com robôs. A Cloudflare em modo "under
     * attack" responde 503 — e um 503 visto três vezes seguidas era, até aqui,
     * promovido a PARTIDO. O sítio está vivo; é a nós que não responde. E não há
     * nada que o dono deste site possa fazer para o resolver: o que um servidor
     * distante faz com um pedido automático não é dele para configurar — pedir a
     * inclusão numa lista branca alheia, quando é sequer possível, não está ao
     * seu alcance.
     *
     * Quando um destes cabeçalhos aparece, o servidor está a dizer-nos, por
     * escrito, que o problema somos nós — e isso é informação melhor do que
     * qualquer heurística sobre o código.
     *
     * O `retry-after` entra aqui de propósito: um servidor que diz *quando* voltar
     * está a anunciar indisponibilidade temporária, não uma página que desapareceu.
     */
    private static $cabecalhos_de_muro = array(
        'cf-mitigated',   // Cloudflare: desafio ou bloqueio aplicado a este pedido.
        'cf-chl-bypass',  // Cloudflare: forma antiga do mesmo desafio.
        'x-datadome',     // DataDome.
        'retry-after',    // "Volta daqui a X" — por definição, temporário.
    );

    /**
     * @param int|null $http_code Código devolvido, ou null se nem chegou a haver resposta.
     * @param string   $error     Token de erro de ligação, se houve.
     * @param array    $headers   Cabeçalhos da resposta, se os houve.
     * @return string Uma das constantes desta classe.
     */
    public function classify( $http_code = null, $error = '', $headers = array() ) {

        /*
         * Se a ligação falhou, o código HTTP (se algum) não é de confiança — e
         * NENHUM erro de ligação, visto uma única vez, prova que o link está morto.
         *
         * O 'dns' e o 'redirect_loop' já estiveram aqui a dar BROKEN de imediato, e
         * era o defeito mais caro do plugin: um soluço de 30 segundos no resolvedor
         * de DNS do alojamento — banal em partilhado — marcava PARTIDOS os 20 links
         * da passagem de uma só vez, e como o estado BROKEN não recua o checked_at,
         * essas linhas só voltavam à fila 168 horas depois. Uma semana inteira com um
         * relatório falso. O 'redirect_loop' tinha o mesmo problema por outra via:
         * muitos sites atiram robôs para ciclos de consentimento de cookies que um
         * browser resolve sem dar por isso.
         *
         * Todos os erros de ligação passam por WARNING e só chegam a PARTIDO pela
         * série de três falhas seguidas do Store (Store::FAIL_STREAK_LIMIT). O 404 e
         * o 410 continuam imediatos, porque aí há certeza: é o próprio servidor de
         * destino a dizer que a página deixou de existir.
         */
        if ( '' !== $error ) {
            return self::WARNING;
        }

        if ( null === $http_code ) {
            return self::WARNING;
        }

        $http_code = (int) $http_code;

        if ( $http_code >= 200 && $http_code < 300 ) {
            return self::OK;
        }

        /*
         * O 404 e o 410 são avaliados ANTES do muro de robôs, e a ordem não é
         * acidental: um 404 servido por trás da Cloudflare continua a ser um 404.
         * O servidor de destino está a falar connosco e a dizer que a página não
         * existe. Cheirar a CDN não pode salvar um link morto — senão trocávamos
         * um falso positivo por um falso negativo, que é pior: o utilizador deixa
         * de saber que tem um problema.
         */
        if ( in_array( $http_code, self::$definitivamente_partidos, true ) ) {
            return self::BROKEN;
        }

        if ( $this->cheira_a_muro( $headers ) ) {
            return self::UNVERIFIABLE;
        }

        if ( in_array( $http_code, self::$nao_verificaveis, true ) ) {
            return self::UNVERIFIABLE;
        }

        // Tudo o resto (5xx, 4xx desconhecidos, 1xx, 3xx que não se resolveram) fica em aviso.
        return self::WARNING;
    }

    /**
     * O servidor disse-nos, por cabeçalho, que o problema somos nós?
     *
     * A comparação é feita em minúsculas porque o `wp_remote_retrieve_headers()`
     * não garante a caixa das chaves entre versões do WordPress e entre
     * transportes (cURL e streams). Confiar na caixa fazia a regra deixar de
     * disparar em produção sem nenhum teste ficar vermelho — os testes usariam
     * minúsculas e passariam sempre.
     *
     * @param array $headers Cabeçalhos da resposta.
     * @return bool
     */
    private function cheira_a_muro( $headers ) {

        if ( empty( $headers ) || ! is_array( $headers ) ) {
            return false;
        }

        foreach ( $headers as $nome => $valor ) {
            if ( in_array( strtolower( (string) $nome ), self::$cabecalhos_de_muro, true ) ) {
                return true;
            }
        }

        return false;
    }
}
