# SpechMedia

Biblioteca e motor de telefonia VoIP em PHP com compilação AOT via [TypePHP](https://github.com/swoole/typephp).

> **Rascunho inicial**: este repositório contém somente a toolchain isolada e um Hello World. Ainda não implementa SIP, SDP, RTP ou codecs G.711. O Swoole passa a integrar o runtime privado de teste, mas sua execução ainda precisa ser validada.

## Início rápido (Linux AMD64 / ARM64)

Requisito no computador: **Docker** funcionando e acesso ao daemon (`docker info`). Nenhum PHP, Composer ou extensão precisa ser instalado no host.

### 1. Preparar o ambiente de compilação

Na raiz do SpechMedia:

```bash
./scripts/setup-toolchain.sh
```

O comando constrói a imagem local `spechmedia-toolchain:0.10.1` usando PHP 8.5, Composer 2, TypePHP 0.10.1 e dependências de compilação. O ambiente permanece dentro do Docker.

### 2. Compilar o Hello World com TypePHP

```bash
./scripts/build-hello.sh
```

O comando executa o TypePHP no container e produz `bin/hello` na máquina local. Usa `--php-builder` para construir um runtime PHP privado com Swoole e POSIX, sem utilizar a biblioteca PHP do host. **A primeira compilação pode demorar**, pois compila também o runtime privado.

### 3. Executar o programa compilado

```bash
./bin/hello
```

Saída esperada em uma compilação concluída com sucesso:

```text
Hello World from SpechMedia!
```

O código-fonte está em [`examples/hello.php`](examples/hello.php):

```php
<?php

function main(): void
{
    echo "Hello World from SpechMedia!\n";
}
```

## Qual comando TypePHP o script executa?

Dentro do container, `scripts/build-hello.sh` chama:

```bash
php /opt/typephp/vendor/bin/tpc.php examples/hello.php \
  --php-builder='extensions: [swoole, posix]; zts: off' \
  --build-dir /workspace/.toolchain/build \
  -o bin/hello
```

O binário resultante é gerado por TypePHP a partir de PHP, e não pelo interpretador do host. O `--php-builder` é intencional: evita depender de `libphp.so` presente no computador. O Swoole é incluído explicitamente no runtime privado para testar posteriormente a integração AOT. `--build-dir` coloca os arquivos C++ gerados em uma pasta gravável, evitando o erro de permissão em `/opt/typephp/vendor/swoole/typephp/build`.

## Isolamento do ambiente

- Não altera `/usr/bin/php`, `/usr/local/bin/php` ou as alternativas do sistema.
- Não utiliza o pcg729 da libspech/SipSwoole.
- Não executa `apt install` no computador; as dependências são instaladas **somente na imagem Docker**.
- Mantém o cache de compilação em `.toolchain/home`, ignorado pelo Git.
- A imagem Docker é própria do projeto; o código e a saída da compilação ficam no diretório local.
- `--php-builder` cria o runtime privado, mas **não significa que o binário final será totalmente estático**. A ligação e as dependências efetivas ainda precisam ser inspecionadas.

## Estado do projeto

O objetivo desta primeira etapa é validar apenas o caminho completo: instalar toolchain isolada, compilar PHP com TypePHP e executar o binário nativo. **O Hello World sem Swoole já foi compilado e executado com sucesso no ambiente local. A variante com Swoole ainda não foi validada após esta alteração.**

Próximos experimentos: confirmar integração de Swoole com sockets UDP no AOT, criar parser RTP e introduzir decodificação/gravação G.711 PCMA/PCMU. A configuração do `php-builder` já solicita Swoole explicitamente, mas é necessário confirmar a compilação, a presença da classe `Swoole\Server` no executável e o funcionamento real de sockets UDP.

### Fontes técnicas

- [TypePHP README](https://github.com/swoole/typephp)
- [PHP builder do TypePHP](https://github.com/swoole/typephp/blob/master/docs/en/LIBPHP_INSTALLER.md)
- [Versões do pacote TypePHP](https://packagist.org/packages/swoole/typephp)
