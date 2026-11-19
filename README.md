# SpechMedia

Biblioteca e motor de telefonia VoIP em PHP com compilação AOT via [TypePHP](https://github.com/swoole/typephp).

> **Rascunho inicial**: este repositório contém somente a toolchain isolada e um Hello World. Ainda não implementa SIP, SDP, RTP, Swoole ou codecs G.711.

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

O comando executa o TypePHP no container e produz `bin/hello` na máquina local. Usa `--php-builder` para construir um runtime PHP privado, sem utilizar a biblioteca PHP do host. **A primeira compilação pode demorar**, pois compila também o runtime privado.

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
  --php-builder -o bin/hello
```

O binário resultante é gerado por TypePHP a partir de PHP, e não pelo interpretador do host. O `--php-builder` é intencional: evita depender de `libphp.so` presente no computador.

## Isolamento do ambiente

- Não altera `/usr/bin/php`, `/usr/local/bin/php` ou as alternativas do sistema.
- Não utiliza o pcg729 da libspech/SipSwoole.
- Não executa `apt install` no computador; as dependências são instaladas **somente na imagem Docker**.
- Mantém o cache de compilação em `.toolchain/home`, ignorado pelo Git.
- A imagem Docker é própria do projeto; o código e a saída da compilação ficam no diretório local.
- `--php-builder` cria o runtime privado, mas **não significa que o binário final será totalmente estático**. A ligação e as dependências efetivas ainda precisam ser inspecionadas.

## Estado do projeto

O objetivo desta primeira etapa é validar apenas o caminho completo: instalar toolchain isolada, compilar PHP com TypePHP e executar o binário nativo. **A compilação ainda não foi executada ou validada neste rascunho.**

Próximos experimentos: confirmar integração de Swoole com sockets UDP no AOT, criar parser RTP e introduzir decodificação/gravação G.711 PCMA/PCMU. Para isso, a configuração do `php-builder` terá de incluir e validar a extensão Swoole; o Hello World não a requer.

### Fontes técnicas

- [TypePHP README](https://github.com/swoole/typephp)
- [PHP builder do TypePHP](https://github.com/swoole/typephp/blob/master/docs/en/LIBPHP_INSTALLER.md)
- [Versões do pacote TypePHP](https://packagist.org/packages/swoole/typephp)
