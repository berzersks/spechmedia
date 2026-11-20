# SpechMedia

Biblioteca e futuro motor de telefonia VoIP em PHP, compilada com [TypePHP](https://github.com/swoole/typephp) (AOT) e Swoole.

A primeira versão origina chamadas SIP por UDP e interpreta SDP de áudio. O endereço e a porta de mídia pertencem à aplicação que fornece a oferta.

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

## Testar uma chamada de saída

Prepare a toolchain conforme o passo 1. Em `example.php`, substitua os valores de `domain`, `username` e `password` no construtor de `SipUserAgent` antes de compilar. Esses valores ficam incorporados ao executável. Na raiz do projeto, configure o endereço de uma interface local, o par UDP que receberá a sinalização e um destino de teste. Os endereços abaixo são reservados para documentação. Substitua-os pelos seus.

```bash
export SIP_LOCAL_IP=192.0.2.10
export SIP_LOCAL_PORT=5062
export SIP_PEER_IP=192.0.2.20
export SIP_PEER_PORT=5060
export SIP_DESTINATION=1002
export SIP_CALL_SECONDS=10

# Informe um endpoint de mídia sob controle da aplicação.
export MEDIA_IP=192.0.2.10
export MEDIA_PORT=40000

bin/tpc.php example.php -O2
./example
```

A execução de `./example` registra o contato, chama o destino e encerra a chamada após o intervalo contado a partir do atendimento. Também aceita uma URI completa em `SIP_DESTINATION`. As portas SIP têm padrão 5060 e a duração tem padrão de 10 segundos. O exemplo exige `MEDIA_IP` e `MEDIA_PORT` explicitamente. O SpechMedia apenas os anuncia no SDP. A aplicação proprietária da porta é responsável por toda a mídia.

O launcher reconhece o arquivo de entrada da raiz e seleciona [example.yml](example.yml). O manifesto inclui `example.php` e a árvore `src/` na unidade de compilação. O ponto de entrada é a função global `main(): void`. Para outro binário que consuma a biblioteca, inclua seu ponto de entrada e o diretório `src/` no respectivo manifesto TypePHP.

### Sinalização disponível

- `SipUserAgent` faz bind em um IPv4 local específico e conecta o socket UDP ao PBX/proxy configurado. Todos os pedidos de diálogo passam por esse mesmo próximo salto. O PBX ou proxy precisa encaminhar a sinalização de retorno pelo mesmo IP e porta. O endereço local anunciado no Contact precisa ser alcançável pelo par.
- `register(timeout: 5)` retorna a resposta de sucesso e lança uma exceção em caso de falha. `unregister()` usa o mesmo contato e Call-ID, incrementa o CSeq e solicita validade zero. O registro dura o prazo aceito pelo servidor. Aplicações longas devem chamar `register()` novamente antes da expiração. A primeira versão não agenda renovação automática nem negocia 423/Min-Expires.
- REGISTER, INVITE e BYE aceitam desafios 401 e 407, inclusive proxy e servidor em sequência. Digest admite MD5, SHA-256 e variantes sess, com qop auth ou sem qop. Um segundo desafio da mesma origem encerra a tentativa. Auth-int, recuperação de nonce stale e múltiplos realms sucessivos do mesmo tipo ficam fora desta versão.
- Cada `SipCall` mantém seu Call-ID, tags, CSeq, oferta e estado. O motor distingue os ACKs transacionais dos ACKs de diálogo, aplica Contact e Record-Route, aceita retransmissões e encerra diálogos adicionais de um fork com ACK e BYE. CANCEL aguarda a primeira provisória. A resposta ao INVITE determina o resultado, inclusive quando o atendimento cruza com o cancelamento. BYE remoto é validado pelas tags e tem a resposta armazenada para retransmissão.
- T1 é configurável no construtor (padrão 0,5 s). O timer de retransmissão do INVITE dobra até a primeira provisória. As demais transações usam T2 de 4 s e retenção de resposta final de 5 s. Há um limite de espera próprio após a primeira provisória (`progressTimeout`, padrão 180 s). O UA limita chamadas ativas a 64, transações de cliente a 512 e respostas de servidor em cache a 256. O parser limita o cabeçalho a 16 KiB e 128 linhas físicas. [Timers SIP sobre UDP](https://www.rfc-editor.org/rfc/rfc3261#section-17) · [Retenção de 2xx de INVITE](https://www.rfc-editor.org/rfc/rfc6026#section-7.2).
- Os callbacks recebem `(SipCall $call, ?SipMessage $message, string $reason)`. São entregues em ordem, numa corrotina por chamada enquanto houver eventos pendentes. Uma espera cooperativa dentro de um callback permite que a recepção continue. Trabalho de CPU e I/O bloqueante continuam sujeitos às regras do event loop do Swoole. Sob saturação da fila, eventos provisórios podem ser descartados. `waitForEnd()` acompanha o estado da sinalização. `stop()` libera o transporte imediatamente. Para encerramento normal use `hangup()`, `waitForEnd()`, `unregister()` e então `stop()`.
- O argumento `log: true` habilita registros de método e status. Cabeçalhos de autenticação e corpos ficam fora do log do motor.

### SDP e limites de interoperabilidade

`new SdpSession($ip, $porta)` oferece PCMA (8) e PCMU (0), ptime de 20 ms e direção sendrecv. Também é possível passar `SdpSession::parse($texto)` a `createCall()` para preservar um SDP preparado externamente. O perfil suportado contém um único fluxo de áudio IPv4 RTP/AVP. `remoteSdp()` fornece endereço, porta, payloads, ptime e direção **do par**. `negotiatedPayloads()` retorna a interseção G.711, na ordem da resposta. Uma resposta final pode aproveitar o SDP provisório do mesmo diálogo. Uma resposta aceita sem áudio compatível recebe ACK seguido de BYE.

Esta versão pressupõe um único próximo salto UDP, sem descoberta DNS/SRV, travessia automática de NAT, TCP, TLS ou IPv6. Não recebe novos INVITEs como UAS. PRACK/100rel, UPDATE, re-INVITE, REFER, ICE, SRTP e sessões com vários fluxos ficam para etapas futuras. Nenhum socket RTP/RTCP é aberto e não há codificação, áudio, DTMF ou gravação.

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

Já foi comprovada a compilação AOT de um Hello World e observada a inicialização dos workers Swoole após a correção manual no cache. O **novo launcher e a automação** passaram por verificações locais de sintaxe, argumentos e idempotência do patch, mas ainda precisam de validação end-to-end em uma instalação Docker limpa. Os testes de sinalização em [tests/signaling.php](tests/signaling.php) usam transporte em memória. Cobrem autenticação, retransmissão, timeout, chamadas simultâneas, ACK duplicado, CANCEL cruzado com atendimento, SDP provisório e BYE remoto. A compilação do exemplo SIP e a interoperabilidade UDP com um PBX aguardam validação manual.

Referências: [TypePHP](https://github.com/swoole/typephp) · [PHP builder](https://github.com/swoole/typephp/blob/master/docs/en/LIBPHP_INSTALLER.md).
