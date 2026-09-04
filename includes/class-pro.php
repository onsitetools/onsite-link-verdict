<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * O portão da versão paga.
 *
 * Existe num sítio só para que, quando o SDK do Freemius entrar, haja **um**
 * ficheiro a mudar e não trinta condições espalhadas pelo código.
 *
 * ✅ **O SDK do Freemius entrou a 20/08/2026.** O que decide, em produção, é o
 * `can_use_premium_code()` dele: verdadeiro quando há licença activa (ou período
 * de experiência) de qualquer plano pago.
 *
 * ── A regra 5 do WordPress.org, e o que ela obriga (21/08/2026) ─────────
 *
 * *"Plugins may not contain functionality that is restricted or locked, only to
 * be made available by payment or upgrade."* Ou seja: o pacote que vai para o
 * repositório **não pode levar o código da Pro lá dentro**, nem fechado à chave.
 *
 * Por isso os ficheiros da Pro levam um sufixo de empacotamento no nome e ficam
 * de fora do pacote gratuito. A consequência para este portão é directa, e é a
 * primeira coisa que ele verifica: **sem o código presente não há Pro nenhuma
 * para ligar**, aconteça o que acontecer à licença.
 *
 * Isto não é cinto e suspensórios. É o que faz com que as dezenas de
 * `Pro::is_active()` espalhadas pelo plugin continuem certas no pacote gratuito
 * sem se lhes tocar — e uma que passasse por engano deixaria de encontrar a
 * classe, e o site do cliente ia abaixo com *class not found*.
 *
 * 🚨 **O que isto NÃO cobre:** a suite corre com `ONSITE_LINK_VERDICT_SKIP_FREEMIUS`
 * definido (ver `tests/bootstrap.php`), portanto **nenhum teste automático toca
 * no Freemius a sério** — não há rede nos testes, e não a vai haver. O caminho
 * real prova-se à mão, num site de ensaio com uma licença verdadeira, e é isso
 * que está registado no CONTEXT. Quem mudar esta função tem de repetir esse
 * ensaio; os testes verdes não dizem nada sobre ela.
 *
 * As duas portas de trás continuam a existir, e são as primeiras a responder:
 * a constante `ONSITE_LINK_VERDICT_PRO_DEV` no `wp-config.php` e o filtro
 * `onsite_link_verdict_pro_is_active`. Servem para desenvolvimento e para os testes.
 */
class Pro {

    /** @var bool|null Resposta do code_is_present(), guardada para o pedido inteiro. */
    private static $codigo_presente = null;

    /**
     * A versão paga está disponível neste site?
     *
     * @return bool
     */
    public static function is_active() {

        if ( ! static::code_is_present() ) {
            return false;
        }

        if ( defined( 'ONSITE_LINK_VERDICT_PRO_DEV' ) && ONSITE_LINK_VERDICT_PRO_DEV ) {
            return true;
        }

        /*
         * O Freemius, quando existe.
         *
         * O `function_exists()` não é paranóia: nos testes o SDK não é
         * carregado de propósito, e um site que copie só a pasta `includes/`
         * também não o tem. Sem esta guarda, o que devia ser "não tens licença"
         * seria um erro fatal em cima do painel de alguém.
         */
        if ( function_exists( 'onsite_link_verdict_fs' ) ) {
            $freemius = onsite_link_verdict_fs();

            if ( is_object( $freemius ) && method_exists( $freemius, 'can_use_premium_code' ) && $freemius->can_use_premium_code() ) {
                return true;
            }
        }

        /**
         * Se a versão Pro está activa.
         *
         * Ponto único de decisão, e último a falar: o que vier antes (a
         * constante de desenvolvimento e o Freemius) já respondeu.
         *
         * @param bool $activa
         */
        return (bool) apply_filters( 'onsite_link_verdict_pro_is_active', false );
    }

    /**
     * O código da versão paga está mesmo neste site?
     *
     * No pacote gratuito não está — os ficheiros da Pro são tirados na
     * preparação do pacote (regra 5, ver o cabeçalho). Pergunta-se por uma classe
     * só, o `Replacer`, porque as seis viajam sempre juntas: ou entram todas no
     * pacote ou não entra nenhuma.
     *
     * A resposta fica guardada para o resto do pedido. Sem isso, um varrimento de
     * mil artigos mandava o autoloader procurar mil vezes um ficheiro que não
     * existe — e no pacote gratuito isso é ler a pasta do disco de cada vez.
     *
     * É `static::` e não `self::` quem chama isto no `is_active()`, de propósito:
     * assim um teste pode estender esta classe, responder "não" aqui, e provar que
     * o portão fecha mesmo com licença válida. É a única forma de exercitar o
     * pacote gratuito a partir de uma árvore que tem os ficheiros todos.
     *
     * @return bool
     */
    public static function code_is_present() {

        if ( null === self::$codigo_presente ) {
            self::$codigo_presente = class_exists( __NAMESPACE__ . '\\Replacer' );
        }

        /**
         * Se o código da versão paga está presente neste site.
         *
         * O filtro fica FORA da cache de propósito: a cache existe para não
         * repetir o class_exists() mil vezes num varrimento, não para congelar a
         * resposta. É o que deixa um teste exercitar o pacote gratuito a partir
         * de uma árvore que tem os ficheiros todos.
         *
         * @param bool $presente
         */
        return (bool) apply_filters( 'onsite_link_verdict_pro_code_is_present', self::$codigo_presente );
    }
}
