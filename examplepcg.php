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

            // CPU do processo: 100% corresponde a um núcleo ocupado.
            // O objeto mantém as amostras entre os callbacks, inclusive no binário AOT.
            $usage = getrusage();
            $cpuState = new \stdClass();
            $cpuState->lastCpuTime = ($usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000)
                + ($usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000);
            $cpuState->lastWallTime = hrtime(true);



            try {
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
                $phone->mountLineCodecSDP('PCMA/8000');
                //$phone->mountLineCodecSDP('G729/8000/1');
                $phone->enableStereoSound();
                $phone->enableAudioRecording();
                $phone->enableAudioMemorySharing();
                $phone->defineAudioFile('/home/lotus/projetos/libspech/music.wav');
                $phone->mediaChannel->setNativeRtpReceive(true);
                $phone->mediaChannel->setReceiveOnlyMode(true);
                //$phone->enableVAD();
                //$phone->voiceActivityTimeout(3);


                //$phone->setCallerId('libspech');
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
                $resourceTimerId = \Swoole\Timer::tick(1000, static function ($id) use ($cpuState, $phone, &$resourceTimerId): void {
                    $usage = getrusage();
                    $now = hrtime(true);
                    $cpuUser = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000;
                    $cpuSystem = $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000;
                    $totalCpuTime = $cpuUser + $cpuSystem;
                    $deltaTime = ($now - $cpuState->lastWallTime) / 1_000_000_000;
                    $cpuUsagePct = $deltaTime > 0
                        ? max(0.0, ($totalCpuTime - $cpuState->lastCpuTime) / $deltaTime * 100)
                        : 0.0;
                    $cpuState->lastCpuTime = $totalCpuTime;
                    $cpuState->lastWallTime = $now;
                    if ($phone->mediaChannel->active) {
                        cli::pcl("MediaChannel active ".$phone->getBuffer()->length()." audio bytes", "bold_yellow");
                    }

                    cli::pcl("--- DEBUG de Recursos ---", "bold_cyan");
                    cli::pcl("Memória atual: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB", "cyan");
                    cli::pcl("Memória pico: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB", "cyan");
                    cli::pcl("CPU Usage: " . round($cpuUsagePct, 2) . "%", "bold_yellow");
                    cli::pcl("CPU user: " . round($cpuUser, 2) . "s | system: " . round($cpuSystem, 2) . "s", "cyan");
                    cli::pcl("---------------------------", "bold_cyan");
                });
                $phone->onAnswer(function (trunkController $phone) {


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
                $phone->call('libspech');


                $phone->saveBufferToWavFile('rec2.wav', $phone->getBuffer()->toString());


                // ====================================================================
                // SESSÃO 9: FINALIZAÇÃO E LIMPEZA
                // ====================================================================
                cli::pcl("Script finalizado", "green");

                // Fecha a conexão SIP e libera recursos
                cli::pcl("Processo cancelado", "red");
                return 0;
            } finally {
                if ($resourceTimerId !== false) {
                    \Swoole\Timer::clear($resourceTimerId);
                }
            }
        });


// Mensagem final indicando que o processo de corotina foi encerrado
    cli::pcl("Processo encerrado com sucesso", "green");

}
