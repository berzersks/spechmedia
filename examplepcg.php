<?php

// Importa as classes necessárias do sistema
use libspech\Cli\cli;
use libspech\Sip\trunkController;
use Swoole\Coroutine;
// A chamada dinâmica preserva a referência exigida pela função externa no binário AOT.
function waitUntilBye(float $seconds, trunkController $phone): void
{
    call_user_func_array('libspech\\Sip\\interruptibleSleep', [$seconds, &$phone->receiveBye]);
}

function main()
{

// Habilita o suporte a corotinas do Swoole para execução assíncrona

    Coroutine::set(['hook_flags' => 0]);


// Carrega o autoloader para importar todas as dependências do projeto
    include '/home/lotus/projetos/libspech/plugins/autoloader.php';


// ============================================================================
// SESSÃO 2: INICIALIZAÇÃO DO AMBIENTE DE COROTINA
// ============================================================================
// Cria o ambiente de execução em corotina do Swoole
        // Cria uma nova corotina para executar o código SIP de forma assíncrona
        Co\run(function () {

            // $s=microtime(true);
            // usleep(500_000);
            // $c = round(microtime(true)-$s,3);
            // cli::pcl("Corotina SIP iniciada em {$c} segundos", "bold_green");
            // exit;
            // ====================================================================
            // SESSÃO 3: CONFIGURAÇÃO DE CREDENCIAIS SIP
            // ====================================================================
            // Busca as credenciais SIP das variáveis de ambiente
            // Se não estiverem definidas, usa strings vazias como fallback
            $username = getenv('SIP_USERNAME') ?: 'ramal';
            $password = getenv('SIP_PASSWORD') ?: 'ramal';
            $domain = getenv('SIP_HOST') ?: 'silva.spechshop.com';


            // Valida se o domínio é um IP ou hostname
            // Se for hostname, resolve para IP usando DNS


            // Instancia o controlador do trunk SIP com as credenciais
            $phone = new trunkController(username: $username, password: $password, host: $domain, port: 5060, domain: $domain, sipIpVersion: 4);
            $phone->setSipIpVersion(4);
            $phone->setPacketTime(20);
            //$phone->mountLineCodecSDP('OPUS/48000/2');
            $phone->mountLineCodecSDP('PCMA/8000/1');
            //$phone->enableStereoSound();
            $phone->enableAudioRecording();
            $phone->enableAudioMemorySharing();
            $phone->defineAudioFile('/home/lotus/projetos/libspech/music.wav');
            $phone->mediaChannel->setNativeRtpReceive(true);
            $phone->mediaChannel->setReceiveOnlyMode(true);
            //$phone->enableVAD();
            //$phone->voiceActivityTimeout(3);


            //$phone->setCallerId('xxxxxxxxxxx');
            // ====================================================================
            // SESSÃO 4: REGISTRO SIP
            // ====================================================================
            // Tenta registrar no servidor SIP com timeout de 10 segundos
            // Se falhar, lança uma exceção e interrompe a execução
            if ($phone->register(5)) {
                cli::pcl("Registrado com sucesso", "green");
            } else {
                cli::pcl("Erro ao registrar", "red");
                return false;
            }

            // ====================================================================
            // SESSÃO 5: CONFIGURAÇÃO DE CALLBACKS DE EVENTOS
            // ====================================================================


            //$phone->mountLineCodecSDP('G729/8000');


            // Habilita a gravação de áudio durante a chamada


            // Callback executado quando uma chamada está tocando (ringing)

            $phone->onRinging(function (trunkController $phone, ?array $packet = null) {
                $method = $packet['method'] ?? $phone->lastPacket['method'];
                cli::pcl($method . " Chamada TOCANDO " . microtime(true), "yellow");
                //\Swoole\Coroutine::sleep(5);
                //$phone->cancel();
            });
            $phone->onFailed(function ($message) use ($phone) {
                cli::pcl("Chamada falhou: $message", "red");

            });
            $phone->onHangup(function (trunkController $phone, ...$context) {
                // Salva o buffer de áudio gravado em um arquivo WAV
                $phone->saveBufferToWavFile('rec2.wav', $phone->getBuffer()->toString());
                // Desbloqueia a corotina para continuar a execução

                cli::pcl("Bye recebido", "red");

            });
            $phone->onSdpReceived(function (trunkController $phone) {
                cli::pcl("SDP recebido " . microtime(true), "green");
                $phone->receiveMedia();
            });
            $phone->onAnswer(function (trunkController $phone) {
                waitUntilBye(10, $phone);


                cli::pcl("Chamada recebida", "green");

                cli::pcl("IP remoto: " . $phone->audioRemoteIp . ':' . $phone->audioRemotePort, "yellow");
                // Inicia o recebimento de mídia (áudio RTP)


                // ================================================================
                // SESSÃO 7: FLUXO DE INTERAÇÃO NA CHAMADA
                // ================================================================

                // Aguarda 10 segundos de forma interruptível (pode ser cancelado se receber BYE)


                // Envia DTMF (tom de teclado) - caractere '*' com duração de 160ms


                $phone->waitSilence(false, 10);


                $buffer = $phone->getBuffer();
                $bufferLen = $buffer->length();


                $phone->send2833('#');


                $cpf = '42017165204';
                waitUntilBye(3, $phone);
                foreach (str_split(substr($cpf, 0, 11)) as $digit) {
                    $phone->send2833($digit);
                    cli::pcl("Digitando: " . $digit, "yellow");
                }
                cli::pcl("Digitado: " . $cpf, "green");
                $phone->waitSilence(false, 10);

                waitUntilBye(30, $phone);


                $phone->bye();


                // Define flags indicando que a chamada foi encerrada
                $phone->receiveBye = true;
                $phone->callActive = false;
            });
            $phone->onKeyPress(function ($event, ...$context) use ($phone) {
                cli::pcl("$phone->calledNumber Digitou: " . $event, "yellow");
            });
            $phone->onPacketOnTimeoutMedia(function ($peer) use ($phone) {
                cli::pcl("Timeout de mídia atingido, encerrando chamada", 'bold_red');
                $phone->bye();

                return true;
            });


            //$phone->enableStereoSound();


            //$phone->call('*53');
            $phone->call('5569999963736');


            $phone->saveBufferToWavFile('rec2.wav', $phone->getBuffer()->toString());


            // ====================================================================
            // SESSÃO 9: FINALIZAÇÃO E LIMPEZA
            // ====================================================================
            cli::pcl("Script finalizado", "green");

            // Fecha a conexão SIP e libera recursos
            cli::pcl("Processo cancelado", "red");
            return 0;
        });


// Mensagem final indicando que o processo de corotina foi encerrado
    cli::pcl("Processo encerrado com sucesso", "green");

}
