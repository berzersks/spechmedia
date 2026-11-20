# SpechMedia

Biblioteca e futuro motor de telefonia VoIP em PHP, compilada com [TypePHP](https://github.com/swoole/typephp) (AOT) e Swoole.

A primeira versão origina chamadas SIP por UDP e interpreta SDP de áudio. A oferta SDP pode ser preparada automaticamente ou fornecida pela aplicação de mídia.

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

Em `example.php`, ajuste `server`, `username`, `password` e `$destination`. As credenciais ficam incorporadas ao executável. A porta UDP do provedor é 5060 por padrão; para alterá-la, passe `serverPort: 5070` no construtor. A duração após o atendimento é de 10 segundos, configurável com `SIP_CALL_SECONDS`.

```bash
bin/tpc.php example.php -O2
./example
```

Dentro de uma corrotina Swoole, o fluxo mínimo é:

```php
$sip = new SipUserAgent(server: 'sip.provedor.com.br', username: '1001', password: 'senha');
$sip->start();
try {
    $sip->register();
    try {
        $call = $sip->createCall('5569999999999');
        $call->invite();
        $call->waitForEnd();
    } finally {
        $sip->unregister();
    }
} finally {
    $sip->stop();
}
```

O launcher reconhece o arquivo de entrada da raiz e seleciona [example.yml](example.yml). O manifesto inclui `example.php` e a árvore `src/` na unidade de compilação. O ponto de entrada é a função global `main(): void`. Para outro binário que consuma a biblioteca, inclua seu ponto de entrada e o diretório `src/` no respectivo manifesto TypePHP.

### Sinalização disponível

- `start()` resolve o IPv4 do servidor e conecta um único socket UDP. O bind padrão usa `0.0.0.0:0`. Depois de conectar, a biblioteca lê o IPv4 escolhido pela rota e a porta atribuída pelo sistema operacional. Esses valores alimentam Via e Contact. O PBX ou proxy deve retornar a sinalização pelo mesmo próximo salto.
- `register(timeout: 5)` retorna a resposta de sucesso e lança uma exceção em caso de falha. `unregister()` usa o mesmo contato e Call-ID, incrementa o CSeq e solicita validade zero. O registro dura o prazo aceito pelo servidor. Aplicações longas devem chamar `register()` novamente antes da expiração. A primeira versão não agenda renovação automática nem negocia 423/Min-Expires.
- REGISTER, INVITE e BYE aceitam desafios 401 e 407, inclusive proxy e servidor em sequência. Digest admite MD5, SHA-256 e variantes sess, com qop auth ou sem qop. Um segundo desafio da mesma origem encerra a tentativa. Auth-int, recuperação de nonce stale e múltiplos realms sucessivos do mesmo tipo ficam fora desta versão.
- Cada `SipCall` mantém seu Call-ID, tags, CSeq, oferta e estado. O motor distingue os ACKs transacionais dos ACKs de diálogo, aplica Contact e Record-Route, aceita retransmissões e encerra diálogos adicionais de um fork com ACK e BYE. CANCEL aguarda a primeira provisória. A resposta ao INVITE determina o resultado, inclusive quando o atendimento cruza com o cancelamento. BYE remoto é validado pelas tags e tem a resposta armazenada para retransmissão.
- T1 é configurável no construtor (padrão 0,5 s). O timer de retransmissão do INVITE dobra até a primeira provisória. As demais transações usam T2 de 4 s e retenção de resposta final de 5 s. Há um limite de espera próprio após a primeira provisória (`progressTimeout`, padrão 180 s). O UA limita chamadas ativas a 64, transações de cliente a 512 e respostas de servidor em cache a 256. O parser limita o cabeçalho a 16 KiB e 128 linhas físicas. [Timers SIP sobre UDP](https://www.rfc-editor.org/rfc/rfc3261#section-17) · [Retenção de 2xx de INVITE](https://www.rfc-editor.org/rfc/rfc6026#section-7.2).
- Os callbacks recebem `(SipCall $call, ?SipMessage $message, string $reason)`. São entregues em ordem, numa corrotina por chamada enquanto houver eventos pendentes. Uma espera cooperativa dentro de um callback permite que a recepção continue. Trabalho de CPU e I/O bloqueante continuam sujeitos às regras do event loop do Swoole. Sob saturação da fila, eventos provisórios podem ser descartados. `waitForEnd()` acompanha o estado da sinalização. `stop()` libera o transporte imediatamente. Para encerramento normal use `hangup()`, `waitForEnd()`, `unregister()` e então `stop()`.
- O argumento `log: true` habilita registros de método e status. Cabeçalhos de autenticação e corpos ficam fora do log do motor.

### Endereços locais, NAT e mídia

| Configuração opcional | Efeito |
| --- | --- |
| Construtor: `localIp`, `localPort` | Interface e porta de bind SIP. A porta zero solicita uma porta disponível. |
| Construtor: `advertisedIp`, `advertisedPort` | Endereço e porta publicados em Via e Contact. Úteis quando existe um mapeamento NAT conhecido. |
| Construtor: `domain` | Domínio SIP da conta e das URIs, caso seja diferente do próximo salto definido por `server`. |
| `createCall()`: `mediaBindIp`, `mediaPort` | Bind da reserva UDP por chamada. O padrão é o IPv4 local da sinalização e porta zero. |
| `createCall()`: `mediaIp`, `advertisedMediaPort` | Endereço anunciado em `c=` e porta anunciada em `m=audio`. Por padrão, correspondem à reserva de mídia. |
| `createCall()`: `codecs`, `ptime` | Nomes `PCMA` e/ou `PCMU`, na ordem desejada, e intervalo em milissegundos. Padrão: ambos, nessa ordem, e 20 ms. |
| `createCall($destino, offer: $sdp)` | Usa a descrição fornecida e deixa a posse da porta com o componente externo. Esta opção substitui a geração automática. |

A descoberta da rota identifica o IP de origem local. Em uma rede com NAT ela pode retornar um IP privado. Os overrides SIP e SDP são independentes e devem refletir mapeamentos alcançáveis pelo par. A biblioteca não descobre o IP público nem cria redirecionamentos de porta.

A oferta automática reserva um socket UDP em `createCall()` e anuncia `a=inactive`: ainda não existe processamento RTP. Se o provedor responder com outra direção ou omitir o atributo, a chamada de sinalização pode ser atendida com a mídia local inativa. O SDP remoto permanece disponível exatamente como recebido. Ofertas explícitas continuam a exigir uma direção compatível na resposta. Chamadas simultâneas mantêm reservas independentes. Encerramento, falha, descarte de uma chamada ainda não iniciada e `stop()` liberam a reserva. Para anunciar mídia ativa gerenciada por outro componente, use um `SdpSession` explícito.

**Consulta sem alocação.** Durante a execução, `getLocalIp()` e `getLocalPort()` informam o socket SIP efetivo; `getServerIp()` informa o IPv4 resolvido e `getServerPort()` a porta configurada. `getAdvertisedIp()` e `getAdvertisedPort()` informam o endereço publicado. Antes de `start()` e depois de `stop()`, os getters de endpoint retornam `null`, exceto a porta configurada do servidor.

Na chamada, `getLocalSdp()`, `getLocalMediaIp()` e `getLocalMediaPort()` descrevem a oferta daquela chamada e continuam consultáveis após o fim. `getMediaBindIp()` e `getMediaBindPort()` informam a reserva **enquanto ela existe**. Para SDP externo, retornam `null`. `getRemoteSdp()` retorna `null` até a chegada de um SDP remoto válido.

**Migração.** O construtor agora começa por `server, username, password`. Para um antigo par `domain/peerIp`, use `server: $peerIp, domain: $domain` ou simplesmente `server: $domain` quando o DNS apontar para o provedor. Transportes injetados implementam `open(string $localIp, int $localPort, UdpEndpoint $server): UdpEndpoint`, retornando o endpoint local efetivo.

### SDP e limites de interoperabilidade

`new SdpSession($ip, $porta)` oferece PCMA (8) e PCMU (0), ptime de 20 ms e direção sendrecv. Também é possível passar `SdpSession::parse($texto)` a `createCall()` para preservar um SDP preparado externamente. O perfil suportado contém um único fluxo de áudio IPv4 RTP/AVP. `remoteSdp()` fornece endereço, porta, payloads, ptime e direção **do par**. `negotiatedPayloads()` retorna a interseção G.711, na ordem da resposta. Uma resposta final pode aproveitar o SDP provisório do mesmo diálogo. Uma resposta aceita sem áudio compatível recebe ACK seguido de BYE.

Esta versão pressupõe um único próximo salto UDP, com resolução IPv4 de nome e sem descoberta SRV, travessia automática de NAT, TCP, TLS ou IPv6. Não recebe novos INVITEs como UAS. PRACK/100rel, UPDATE, re-INVITE, REFER, ICE, SRTP e sessões com vários fluxos ficam para etapas futuras. A reserva UDP não envia nem processa RTP ou RTCP. Não há codificação, áudio, DTMF ou gravação.

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

Já foi comprovada a compilação AOT de um Hello World e observada a inicialização dos workers Swoole após a correção manual no cache. O **novo launcher e a automação** passaram por verificações locais de sintaxe, argumentos e idempotência do patch, mas ainda precisam de validação end-to-end em uma instalação Docker limpa. Os testes de sinalização em [tests/signaling.php](tests/signaling.php) usam mensagens SIP em memória e sockets de loopback para verificar a seleção dos endpoints e o ciclo de vida das reservas. Cobrem autenticação, retransmissão, timeout, chamadas simultâneas, ACK duplicado, CANCEL cruzado com atendimento, SDP provisório e BYE remoto. A compilação do exemplo SIP e a interoperabilidade UDP com um PBX aguardam validação manual.

Referências: [TypePHP](https://github.com/swoole/typephp) · [PHP builder](https://github.com/swoole/typephp/blob/master/docs/en/LIBPHP_INSTALLER.md).
