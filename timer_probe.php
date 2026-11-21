<?php

use Swoole\Coroutine;
use Swoole\Timer;

final class TimerState
{
    public int $ticks = 0;
    public bool $afterExecuted = false;
}

function main(): void
{
    echo "Swoole carregado: ";
    var_dump(extension_loaded('swoole'));

    echo "Timer::tick disponivel: ";
    var_dump(method_exists(Timer::class, 'tick'));

    Coroutine::set(['hook_flags' => 0]);

    Coroutine\run(static function (): void {
        $state = new TimerState();

        $timerId = Timer::tick(
            100,
            static function (int $id) use ($state): void {
                try {
                    ++$state->ticks;

                    echo "Tick #{$state->ticks} ID={$id}\n";
                } catch (Throwable $e) {
                    echo "Erro no callback: {$e->getMessage()}\n";
                }
            }
        );

        echo "Timer ID: ";
        var_dump($timerId);

        Timer::after(250, static function () use ($state): void {
            try {
                $state->afterExecuted = true;
                echo "Timer::after executado!\n";
            } catch (Throwable $e) {
                echo "Erro no after: {$e->getMessage()}\n";
            }
        });

        Coroutine::sleep(0.65);

        if ($timerId !== false) {
            Timer::clear($timerId);
        }

        echo "Total ticks: {$state->ticks}\n";
        echo "After executou: ";
        var_dump($state->afterExecuted);
    });
}
