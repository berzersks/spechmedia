# SpechMedia

Biblioteca e futuro motor de telefonia VoIP em PHP, compilada com [TypePHP](https://github.com/swoole/typephp) (AOT) e Swoole.

> Rascunho inicial: toolchain isolada, Hello World e exemplo de servidor Swoole. SIP, SDP, RTP e G.711 ainda não estão implementados.

## Início rápido — Linux AMD64 / ARM64

Requisito no host: Docker instalado e funcionando (`docker info`). Não é necessário instalar PHP ou Composer no computador.

### 1. Preparar a toolchain

```bash
./scripts/setup-toolchain.sh
```

Esse comando monta a imagem local `spechmedia-toolchain:0.10.1` com PHP 8.5, Composer e TypePHP 0.10.1, sem modificar o PHP do sistema.

### 2. Compilar o Hello World

```bash
bin/tpc.php hello.php
```

### 3. Executar

```bash
./hello
```

Saída esperada: `Hello World from SpechMedia!`

O exemplo mínimo está em [hello.php](hello.php).

## Outros comandos

```bash
bin/tpc.php hello.php -O3
bin/tpc.php hello.php -o meu-programa

# Exemplo existente de servidor TCP Swoole
bin/tpc.php examples/hello.php -o swoole-server
./swoole-server

# Atalho antigo, mantido para compatibilidade
./scripts/build-hello.sh
./bin/hello
```

`bin/tpc.php` é um **launcher Bash executável** e não depende de `php` no host. Invoque-o diretamente (não use `php bin/tpc.php`). O compilador aceita opções usuais do TypePHP e deve ser executado dentro do projeto. O servidor de exemplo usa processos/workers; não exige `SWOOLE_THREAD`.

## Toolchain e correção do Swoole

O launcher usa Docker e configura automaticamente:

```bash
--php-builder='extensions: [swoole, posix]; zts: off' \
--build-dir /workspace/.toolchain/build
```

O `scripts/prepare-swoole-source.py` registra os arquivos C++ principais do Swoole no `config.m4` **do cache privado** antes de o TypePHP gerar o PHP estático. Isso contorna o problema diagnosticado em que 126 fontes existiam, mas nenhum objeto principal era registrado no Makefile, resultando em `undefined reference to swoole_module_entry`. O script também corrige as árvores de fontes já preparadas e regenera o `configure` correspondente. Não edita o PHP do host nem o repositório oficial do Swoole.

A primeira compilação com Swoole pode precisar construir o PHP privado; compilações seguintes reutilizam os caches em `.toolchain/home` e `.toolchain/build`.

**Isolamento:** não altera `/usr/bin/php`, `update-alternatives` nem o pcg729/libspech/SipSwoole. A imagem Docker instala suas próprias dependências. O executável AOT não é necessariamente totalmente estático; confirme com `file hello` e `ldd hello`.

## Estado de validação

Já foi comprovada a compilação AOT de um Hello World e observada a inicialização dos workers Swoole após a correção manual no cache. O **novo launcher e a automação** passaram por verificações locais de sintaxe, argumentos e idempotência do patch, mas ainda precisam de validação end-to-end em uma instalação Docker limpa. A recepção UDP e o processamento RTP não foram testados.

Referências: [TypePHP](https://github.com/swoole/typephp) · [PHP builder](https://github.com/swoole/typephp/blob/master/docs/en/LIBPHP_INSTALLER.md).
