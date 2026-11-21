# SpechMedia

Biblioteca de telefonia VoIP em PHP, compilada com [TypePHP](https://github.com/swoole/typephp) (AOT) e Swoole.

O SpechMedia origina chamadas SIP por UDP e grava áudio RTP G.711 recebido do destino em WAV. A oferta SDP pode ser preparada automaticamente ou fornecida por uma aplicação que gerencie a própria mídia.

## Início rápido — Linux AMD64 / ARM64

Requisito no host: Docker instalado e funcionando (`docker info`). Não é necessário instalar PHP ou Composer no computador.

### 1. Preparar a toolchain

```bash
./scripts/setup-toolchain.sh
```

Esse comando monta a imagem local `spechmedia-toolchain:0.10.1` com PHP 8.5, Composer e TypePHP 0.10.1, sem modificar o PHP do sistema.

Para escolher extensões opcionais, abra o [gerador de comando de setup](setup.html) no navegador, marque as opções e execute o comando na raiz do repositório. Por exemplo, `./scripts/setup-toolchain.sh sockets bcmath` prepara a imagem com essas extensões e as inclui na próxima compilação com `bin/tpc.php`. Executar o setup sem argumentos restaura a seleção padrão.

O runtime usa Swoole e POSIX por padrão. Selecionar Swow substitui o Swoole e desativa seus hooks. A opção `parallel` prepara o PHP com ZTS. O Xdebug fica disponível no PHP CLI da imagem.

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

Em `example.php`, ajuste `server`, `username`, `password` e `$destination`. As credenciais ficam incorporadas ao executável. A porta UDP do provedor é 5060 por padrão; para alterá-la, passe `serverPort: 5070` no construtor. A duração padrão do exemplo após o atendimento é de 60 segundos, configurável com `SIP_CALL_SECONDS`.

```bash
bin/tpc.php example.php -O2
./example
```

O exemplo oferece PCMA com `sendrecv` e ptime de 20 ms. Antes do REGISTER, usa o executável `ffmpeg` do PATH para converter `/home/lotus/projetos/libspech/music.wav` em PCMA mono a 8 kHz na memória. Para escolher outro WAV, defina `SIP_AUDIO_FILE` com o caminho absoluto do arquivo. Após o ACK da chamada atendida, o exemplo reproduz o áudio em loop se o par aceitar recebê-lo. Ao encerrar, salva o áudio recebido em `rec.wav`.

Dentro de uma corrotina Swoole, o fluxo mínimo é:

```php
$sip = new SipUserAgent(server: 'sip.provedor.com.br', username: '1001', password: 'senha');
$sip->start();
try {
    $sip->register();
    try {
        $call = $sip->createCall('5569999999999');
        $call->enableAudioRecording();
        $call->onHangup(static function (SipCall $call): void {
            $call->saveBufferToWavFile('rec.wav', $call->getBuffer());
        });
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
- T1 é configurável no construtor (padrão 0,5 s). O timer de retransmissão do INVITE dobra até a primeira provisória. As demais transações usam T2 de 4 s e retenção de resposta final de 5 s. Há um limite de espera próprio após a primeira provisória (`progressTimeout`, padrão 180 s). Por padrão o UA admite 64 chamadas (incluindo reservas RTP) e 512 transações de cliente. Os parâmetros opcionais `maxCalls` e `maxTransactions` do construtor permitem dimensionar esses limites. O cache de respostas de servidor comporta 256 entradas. O parser limita o cabeçalho a 16 KiB e 128 linhas físicas. [Timers SIP sobre UDP](https://www.rfc-editor.org/rfc/rfc3261#section-17) · [Retenção de 2xx de INVITE](https://www.rfc-editor.org/rfc/rfc6026#section-7.2).
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

A oferta automática reserva um socket UDP em `createCall()` e anuncia `a=inactive` por padrão. `enableAudioRecording()`, antes de `invite()`, altera a oferta para `a=recvonly` e usa esse socket para receber áudio. O SDP remoto permanece disponível exatamente como recebido. Chamadas simultâneas mantêm reservas independentes. Encerramento, falha, descarte de uma chamada ainda não iniciada e `stop()` liberam a reserva. Para mídia gerenciada por outro componente, use um `SdpSession` explícito.

**Consulta sem alocação.** Durante a execução, `getLocalIp()` e `getLocalPort()` informam o socket SIP efetivo; `getServerIp()` informa o IPv4 resolvido e `getServerPort()` a porta configurada. `getAdvertisedIp()` e `getAdvertisedPort()` informam o endereço publicado. Antes de `start()` e depois de `stop()`, os getters de endpoint retornam `null`, exceto a porta configurada do servidor.

Na chamada, `getLocalSdp()`, `getLocalMediaIp()` e `getLocalMediaPort()` descrevem a oferta daquela chamada e continuam consultáveis após o fim. `getMediaBindIp()` e `getMediaBindPort()` informam a reserva **enquanto ela existe**. Para SDP externo, retornam `null`. `getRemoteSdp()` retorna `null` até a chegada de um SDP remoto válido.

**Migração.** O construtor agora começa por `server, username, password`. Para um antigo par `domain/peerIp`, use `server: $peerIp, domain: $domain` ou simplesmente `server: $domain` quando o DNS apontar para o provedor. Transportes injetados implementam `open(string $localIp, int $localPort, UdpEndpoint $server): UdpEndpoint`, retornando o endpoint local efetivo.

### SDP e limites de interoperabilidade

`new SdpSession($ip, $porta)` oferece PCMA (8) e PCMU (0), ptime de 20 ms e direção sendrecv. Também é possível passar `SdpSession::parse($texto)` a `createCall()` para preservar um SDP preparado externamente. O perfil suportado contém um único fluxo de áudio IPv4 RTP/AVP. `remoteSdp()` fornece endereço, porta, payloads, ptime e direção **do par**. `negotiatedPayloads()` retorna a interseção G.711, na ordem da resposta. Uma resposta final pode aproveitar o SDP provisório do mesmo diálogo. Uma resposta aceita sem áudio compatível recebe ACK seguido de BYE.

Esta versão pressupõe um único próximo salto UDP, com resolução IPv4 de nome. Descoberta SRV, travessia automática de NAT, TCP, TLS, IPv6, INVITEs de entrada, PRACK/100rel, UPDATE, re-INVITE, REFER, ICE, SRTP e sessões com vários fluxos ficam para etapas futuras.

### Reprodução RTP

Prepare o arquivo com `$alaw = AlawPlayback::loadWav($arquivo)` antes de iniciar a sinalização e passe os bytes a `$call->enableAudioPlayback($alaw)` antes de `invite()`. A classe pertence ao namespace `SpechMedia\Media`. O mesmo áudio preparado pode ser usado em várias chamadas. Habilitar apenas a reprodução oferece PCMA com `sendonly`. Habilitar também a gravação oferece `sendrecv`. O emissor usa o socket reservado da chamada, o endereço e porta do SDP aceito e o ptime da resposta.

### Recepção RTP e gravação

- A oferta `recvonly` aceita respostas `sendonly` ou `inactive` com G.711 em comum. Uma resposta `inactive` mantém a mídia suspensa. PCMA usa PT 8 e PCMU usa PT 0. O receptor aceita tamanhos de payload variáveis, de até 8.000 amostras por pacote. [RFC 3264](https://www.rfc-editor.org/rfc/rfc3264#section-6.1) · [RFC 3551](https://www.rfc-editor.org/rfc/rfc3551#section-4.5.14).
- `enableAudioRecording(allowSendrecvAnswer: true)` também aceita uma resposta `sendrecv`, explícita ou implícita pela ausência do atributo de direção. A oferta continua `recvonly`, o motor opera somente em recepção e aplica os mesmos filtros de origem e codec.
- O receptor começa antes do INVITE. Até 16 pacotes aguardam a primeira resposta SDP válida e só entram na gravação após validação. Early media de `180` ou `183` usa o primeiro diálogo provisório compatível. O `200 OK` pode reutilizar o SDP desse diálogo ou fornecer outro. O áudio provisório já aceito permanece na gravação.
- A política de origem exige o IPv4 e a porta anunciados no SDP remoto. Provedores que enviam RTP de outra porta ou endereço exigem ajuste na rede ou no provedor. A sessão fixa o primeiro SSRC aceito de cada fluxo. Uma troca de origem, conjunto de payloads ou diálogo final reinicia essa seleção. RTP simétrico, RTCP e mudanças espontâneas de SSRC ficam fora desta versão.
- Uma janela de 16 números de sequência resolve reordenação e duplicação. Pacotes atrasados para além da janela são descartados. Lacunas são omitidas do WAV e a gravação concatena as amostras recebidas em ordem de sequência. A janela é esvaziada ao terminar o fluxo.
- `enableAudioRecording(maxBytes: 4_800_000)` define o limite de PCM por chamada. O padrão corresponde a cinco minutos de áudio mono a 8 kHz. Ao atingir o limite, a gravação preserva o início e descarta as amostras excedentes. `getRecordedAudioBytes()` consulta o tamanho sem montar a string PCM, e `isRecordingTruncated()` indica descarte por falta de espaço.
- `getBuffer()` retorna uma string binária PCM de 16 bits little-endian e continua disponível após o encerramento. Consultar o buffer pode alocar uma cópia proporcional à gravação. `saveBufferToWavFile($arquivo, $buffer)` grava WAV somente quando chamado e retorna `false` para um buffer vazio. Nesse caso, um arquivo já existente fica intacto. Para exportar os blocos da gravação sem materializar o PCM completo, use `$call->saveRecordingToWavFile($arquivo)`.

## Outros comandos

```bash
bin/tpc.php examplepcg.php -O3
bin/tpc.php examplepcg.php -o 

# Exemplo existente de servidor TCP Swoole
bin/tpc.php examples/hello.php -o swoole-server
./swoole-server

# Atalho antigo, mantido para compatibilidade
./scripts/build-hello.sh
./bin/hello
```

`bin/tpc.php` é um **launcher Bash executável** e não depende de `php` no host. Invoque-o diretamente (não use `php bin/tpc.php`). O compilador aceita opções usuais do TypePHP e deve ser executado dentro do projeto. O servidor de exemplo usa processos/workers; não exige `SWOOLE_THREAD`.

Os benchmarks AOT, as medições de CPU e memória e os comandos de reprodução estão no [relatório de desempenho](benchmarks/README.md).

## Toolchain e correção do Swoole

Com a seleção padrão, o launcher usa Docker e configura automaticamente:

```bash
--php-builder='extensions: [swoole, posix]; zts: off' \
--build-dir /workspace/.toolchain/build
```

O `scripts/prepare-swoole-source.py` registra os arquivos C++ principais do Swoole no `config.m4` **do cache privado** antes de o TypePHP gerar o PHP estático. Isso contorna o problema diagnosticado em que 126 fontes existiam, mas nenhum objeto principal era registrado no Makefile, resultando em `undefined reference to swoole_module_entry`. O script também corrige as árvores de fontes já preparadas e regenera o `configure` correspondente. Não edita o PHP do host nem o repositório oficial do Swoole.

A primeira compilação com Swoole pode precisar construir o PHP privado; compilações seguintes reutilizam os caches em `.toolchain/home` e `.toolchain/build`.

**Isolamento:** não altera `/usr/bin/php`, `update-alternatives` nem o pcg729/libspech/SipSwoole. A imagem Docker instala suas próprias dependências. O executável AOT não é necessariamente totalmente estático; confirme com `file hello` e `ldd hello`.

## Estado de validação

Execute as suítes locais com PHP 8.5 e Swoole disponíveis:

```bash
php tests/media.php
php tests/signaling.php
```

[tests/media.php](tests/media.php) verifica G.711, parsing e reordenação RTP, limites de gravação e a estrutura e o conteúdo do WAV. [tests/signaling.php](tests/signaling.php) usa SIP em memória e RTP sintético em sockets de loopback para verificar early media, isolamento entre chamadas, liberação das portas e retenção da gravação após BYE remoto e local. Também cobre autenticação, retransmissões, CANCEL e timeout. A interoperabilidade com um provedor SIP aguarda validação manual.

Referências: [TypePHP](https://github.com/swoole/typephp) · [PHP builder](https://github.com/swoole/typephp/blob/master/docs/en/LIBPHP_INSTALLER.md).
