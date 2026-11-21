<?php
declare(strict_types=1);

use libspech\Cli\cli;
use SpechMedia\Media\AlawPlayback;
use SpechMedia\Sip\SipCall;
use SpechMedia\Sip\SipMessage;
use SpechMedia\Sip\SipUserAgent;
use Swoole\Coroutine;

function main(): void
{
    Coroutine::set(['hook_flags' => 0]);
    include '/home/lotus/projetos/libspech/plugins/autoloader.php';


    $wav = getenv('SIP_AUDIO_FILE') ?: '/home/lotus/projetos/libspech/music.wav';
    $alaw = AlawPlayback::loadWav($wav);

    Co\run(static function () use ($alaw): void {
        $sip = null;
        $registered = false;


        // CPU do processo: 100% corresponde a um núcleo ocupado.
        // O objeto mantém as amostras entre os callbacks, inclusive no binário AOT.
        $usage = getrusage();
        $cpuState = new \stdClass();
        $cpuState->lastCpuTime = ($usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000)
            + ($usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000);
        $cpuState->lastWallTime = hrtime(true);


        try {
            // 1. Substitua o domínio, o ramal e a senha pelos dados do seu servidor SIP.
            $sip = new SipUserAgent(
                server: 'silva.spechshop.com',
                username: 'ramal',
                password: 'ramal'
            );
            $destination = 'AOT';
            $seconds = positiveSetting('SIP_CALL_SECONDS', '60', 86400);


            // 2. Iniciar o transporte UDP.
            $sip->start();

            // 3. Registrar o ramal. Uma falha no REGISTER lança uma exceção.
            $registration = $sip->register();
            $registered = true;
            echo "Ramal registrado. {$registration->line}\n";

            // 4. Preparar a chamada e habilitar a gravação do áudio remoto.
            $call = $sip->createCall($destination, codecs: ['PCMA'], ptime: 20);
            $call->enableAudioRecording();
            $call->enableAudioPlayback($alaw);
            echo "Porta reservada para mídia: " . $call->getLocalMediaPort() . "\n";

            // 5. Configurar os eventos antes do INVITE.
            $call->onRinging(static function (SipCall $call, ?SipMessage $message, string $reason): void {
                echo "Telefone tocando (180 Ringing).\n";
            });

            $showSdp = static function (?SipMessage $message): void {
                if ($message !== null && $message->body !== ''
                    && strtolower(trim(explode(';', $message->header('content-type'), 2)[0])) === 'application/sdp') {
                    $body = preg_replace('/[^\x20-\x7e\n]/', '', str_replace("\r\n", "\n", $message->body));
                    echo 'SDP remoto em ' . $message->line . ":\n" . rtrim($body ?? '') . "\n";
                }
            };
            $call->onProgress(static function (SipCall $call, ?SipMessage $message, string $reason) use ($showSdp): void {
                echo 'Progresso SIP: ' . $message?->line . "\n";
                $showSdp($message);
            });

            $call->onAnswer(static function (SipCall $call, ?SipMessage $message, string $reason) use ($seconds): void {
                echo 'Chamada atendida. ' . $message?->line . "\n";
                echo "Call-ID: {$call->id}\n";
                $sdp = $call->getRemoteSdp();
                if ($sdp !== null) {
                    echo "SDP remoto:\n" . $sdp->wire();
                    echo 'Payloads negociados: ' . implode(', ', $call->negotiatedPayloads()) . "\n";
                }

                // Um BYE remoto interrompe a espera antes do prazo.
                if (!$call->waitForEnd(timeout: (float) $seconds)) {
                    $call->hangup();
                }
            });

            $saveRecording = static function (SipCall $call): void {
                try {
                    if ($call->saveBufferToWavFile('rec.wav', $call->getBuffer())) {
                        echo 'Gravação salva em rec.wav (' . $call->getRecordedAudioBytes() . " bytes de PCM).\n";
                    } else {
                        echo "Nenhum áudio RTP recebido para gravar.\n";
                    }
                } catch (Throwable $error) {
                    fwrite(STDERR, 'Falha ao salvar rec.wav: ' . $error->getMessage() . "\n");
                }
            };
            $call->onHangup(static function (SipCall $call, ?SipMessage $message, string $reason) use ($saveRecording): void {
                echo "Chamada encerrada. $reason\n";
                $saveRecording($call);
            });

            $call->onFailed(static function (SipCall $call, ?SipMessage $message, string $reason) use ($saveRecording, $showSdp): void {
                echo 'Falha na chamada: ' . ($message?->line ?? 'sem resposta SIP') . " ($reason)\n";
                $showSdp($message);
                $saveRecording($call);
                //var_dump($call);
            });


            $resourceTimerId = \Swoole\Timer::tick(1000, static function ($id) use (&$call, $cpuState): void {
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
                cli::pcl(strlen($call->getBuffer() ?? "")." audio bytes", 'bold_yellow');

                cli::pcl("--- DEBUG de Recursos ---", "bold_cyan");
                cli::pcl("Memória atual: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB", "cyan");
                cli::pcl("Memória pico: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB", "cyan");
                cli::pcl("CPU Usage: " . round($cpuUsagePct, 2) . "%", "bold_yellow");
                cli::pcl("CPU user: " . round($cpuUser, 2) . "s | system: " . round($cpuSystem, 2) . "s", "cyan");
                cli::pcl("---------------------------", "bold_cyan");
            });



            // 6. Enviar o INVITE.
            echo "Chamando $destination. Call-ID: {$call->id}\n";
            try {
                $call->invite();
            } catch (Throwable $error) {
                fwrite(STDERR, $error->getMessage() . "kkk \n");
            }

            // 7. Aguardar o encerramento local ou remoto.
            $call->waitForEnd();
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "kkk \n");
        } finally {
            if ($resourceTimerId !== false) {
                \Swoole\Timer::clear($resourceTimerId);
            }

            // 8. Remover o registro e liberar o transporte.
            try {
                if ($registered) {
                    $sip->unregister();
                }
            } catch (Throwable $error) {
                fwrite(STDERR, 'Falha no desregistro: ' . $error->getMessage() . "\n");
            } finally {
                $sip?->stop();
            }
        }
    });
}

function positiveSetting(string $key, string $fallback, int $maximum): int
{
    $value = getenv($key);
    $number = $value === false ? $fallback : $value;
    if (!preg_match('/^[0-9]+$/D', $number) || (int) $number < 1 || (int) $number > $maximum) {
        throw new RuntimeException("Defina $key entre 1 e $maximum");
    }
    return (int) $number;
}
