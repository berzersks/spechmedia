# Desempenho de mídia do SpechMedia

A recepção UDP passou de 82.834 para 231.368 pacotes/s com PCMA de 20 ms, 1.000 sessões e AOT `-O2`. Esse número inclui o emissor local no mesmo processo. Em outro teste, 1.000 diálogos SIP coexistiram, receberam áudio individualizado e encerraram por BYE. A sinalização desse teste foi em memória.

## Reprodução

Na raiz do repositório, com a imagem privada do projeto disponível, execute os comandos abaixo. `CPU` escolhe um processador lógico do Linux e pode ser omitida. O script extrai o código original de `376acdc` para `build/`, compila os quatro binários e grava um CSV em um diretório próprio. O teste AOT adapta apenas o carregamento das classes e o ponto de entrada das suítes PHP.

```bash
CPU=4 benchmarks/compare.sh
SPECHMEDIA_SCALE_TEST=1000 python3 benchmarks/test-aot.py

bin/tpc.php benchmarks/experiments.yml -O2 -o /workspace/build/experiments
PT=8 PTIME=20 taskset -c 4 build/experiments
MODE=burst taskset -c 4 build/experiments
MODE=flat taskset -c 4 build/experiments
MODE=segmented taskset -c 4 build/experiments
```

Ambiente medido em 19/11/2026. Intel Core i5-13450HX, afinidade na CPU lógica 4, GCC 12.2.0, PHP 8.5.13 NTS, Swoole 6.2.6 e imagem Docker local `sha256:20c6f2cf93b6759a0604597bb2144692507d4c557fb3819d64dd1ed6adbb7b22`. O Composer da imagem registra `swoole/typephp v0.10.1`, revisão `c5599c5c9e8597e7124dd79db94739cabe39c526`. O banner do compilador dessa distribuição informa 0.10.0. A instalação inicial do projeto consulta o stable do PECL, portanto uma imagem reconstruída futuramente pode incorporar outra versão do Swoole.

## Resultados

Medianas de cinco processos por configuração, alternando a ordem dos binários, com ptime de 20 ms. As funções puras usam uma sessão. O processamento em memória e o UDP usam 1.000. Cada medição do decodificador na matriz inclui o controle do benchmark.

| Operação | Codec | Original -O2, pacotes/s | Atual -O2 | Original -O3 | Atual -O3 |
|---|---|---:|---:|---:|---:|
| Decodificação | PCMA | 163.841 | 4.274.193 | 162.460 | 4.213.144 |
| Decodificação | PCMU | 164.158 | 4.150.439 | 161.542 | 3.724.425 |
| Parser público com objeto | PCMA | 1.208.133 | 1.576.840 | 1.248.610 | 1.592.127 |
| Datagrama até PCM, em memória | PCMA | 124.766 | 1.559.163 | 125.848 | 1.594.579 |
| Datagrama até PCM, em memória | PCMU | 124.945 | 1.532.440 | 124.388 | 1.577.091 |
| UDP até PCM | PCMA | 82.834 | 231.368 | 84.755 | 234.374 |
| UDP até PCM | PCMU | 83.677 | 227.638 | 83.072 | 224.462 |

Em UDP com 1.000 sessões PCMA, o consumo combinado do emissor e do receptor em `-O2` caiu de 12.055 para 4.306 ns de CPU/pacote. O pico mediano de RSS passou de 82.756 para 81.452 KiB. Em PCMU o pico passou de 82.024 para 82.604 KiB. O custo residente adicional antes de receber mídia ficou próximo de 29 kB por sessão UDP nos dois binários. O RSS inclui processo, sockets em espaço de usuário, corrotinas, dados sintéticos e gravações. A memória dos buffers de socket no kernel fica fora dessa métrica.

Com um único socket, as pausas do gerador têm grande peso. Na varredura PCMA de 20 ms em `-O3`, por exemplo, o original fez 13.519 pacotes/s e o atual 12.093. A ordem entre `-O2` e `-O3` também variou com o codec e a etapa medida. O comando padrão continua em `-O2`.

### Como ler a matriz

- A rodada 0 contém 288 casos. Os modos `decode` e `parse` medem funções puras. `pipeline` e `udp` cobrem 1, 10, 100, 500 e 1.000 sessões, ambos os codecs e ptime de 10, 20 e 60 ms. As rodadas 1 a 5 repetem 160 casos no total. [CSV com todas as medições](results.csv)
- Cada processo pré-gera `max(64, 8192 div sessões)` datagramas com números de sequência crescentes, timestamps progressivos e áudio determinístico. A rodada entrega cada datagrama a todas as sessões. O emissor UDP cede por 1 ms a cada 16 rodadas. Ptime define o tamanho do áudio. O gerador envia em rajadas. O tempo inclui drenagem do receptor e exclui criação de sessões, preparação dos pacotes e verificação final de PCM.
- `mean_ns` é tempo de parede por pacote. P50 e P95 são percentis da duração de uma rodada dividida pelas sessões. Com uma sessão cada observação corresponde a um pacote. Em UDP as observações incluem envio e eventuais suspensões do emissor. Latência de chegada individual no kernel exigiria instrumentação diferente.
- `cpu_us` soma tempo de usuário e de sistema do processo durante o intervalo cronometrado. A fração da CPU lógica usada é `1000 × cpu_us / wall_ns`. A medição não atribui ao receptor o trabalho do emissor, do kernel em outros contextos ou de outros processos. O delta de RSS por sessão usa duas leituras de `/proc/self/status`, com resolução limitada por páginas e reaproveitamento do alocador.
- O teste compara PCM e contabiliza pacotes ausentes pelo comprimento gravado. Foram 5.451.568 datagramas UDP na matriz e nas repetições, com zero ausentes nas duas versões. As rejeições intencionais por origem, codec, SSRC, duplicata e cabeçalho inválido são verificadas nas suítes funcionais. A matriz exerce tráfego válido em ordem. Para dimensionar produção ainda faltam testes prolongados com jitter, perdas, simultaneidade na sinalização sob carga de mídia e rede física.

## Escolhas de implementação

**Recepção.** Com o binário 0.10.1, a chamada posicional a `recvfrom($peer, $timeout)` leu o datagrama e deixou o peer nulo. O desempacotamento de `[&$peer, -1.0]`, preparado uma vez por socket, preservou IP e porta na corrotina real. A implementação do Swoole 6.2.6 aloca uma string para a leitura e devolve os dados do peer. Seu stub de socket não expõe `recvfromInto()`. Uma corrotina por gravação espera no socket até haver dados ou fechamento. A recepção não escreve arquivos. [Stub da extensão](https://github.com/swoole/swoole-src/blob/v6.2.6/ext-src/stubs/php_swoole_socket_coro.stub.php) · [Referências e chamadas dinâmicas no TypePHP 0.10.1](https://github.com/swoole/typephp/blob/v0.10.1/docs/en/INCOMPATIBLE_PHP_FEATURES.md)

**RTP.** A sessão filtra a origem e o PT negociado. Para pacotes em sequência com cabeçalho fixo, o receptor valida a versão e os flags pelo primeiro octeto, confere o tamanho e compara os quatro bytes do SSRC em ordem de rede. Passa offset e comprimento do payload diretamente ao buffer. Isso elimina o objeto, o array de `unpack`, a extração do timestamp e a cópia de `substr` no caso comum. O parser completo continua responsável por CSRC, extensão, padding e pela representação pública. A fila original de 16 posições lógicas atende início, lacunas e reordenação. O teste diferencial compara os bytes produzidos pelos dois caminhos durante a evolução da janela. [Formato do cabeçalho RTP](https://www.rfc-editor.org/rfc/rfc3550#section-5.1)

**G.711.** PHP constrói uma tabela compacta de 512 bytes por lei com as fórmulas originais. O kernel C++ recebe strings Zend e índices por valor, lê diretamente a faixa validada e preenche uma única string PCM do tamanho final. As entradas são pares de bytes little-endian, sem depender de alinhamento ou endianness da CPU. O compilador resolve a presença do stub em compilação. O PHP interpretado mantém um loop com lookup e as mesmas entradas. Os SHA-256 dos 256 valores de cada lei foram obtidos da versão original e conferidos também nos binários compilados. A inspeção do código confirma as cópias eliminadas no caminho comum. A medição não incluiu um contador global de alocações por pacote. [Integração C++ da versão utilizada](https://github.com/swoole/typephp/blob/v0.10.1/docs/en/MIXED_CPP_PHP.md)

No experimento isolado em `-O2`, com a tabela já construída, 160 amostras PCMA custaram 5.435 ns com `std::array(Type::String, 256)` e concatenação, 3.072 ns com `strtr` binário em lote e 159 ns com o kernel. Em PCMU foram 5.374, 3.033 e 151 ns. São medianas de sete lotes de 4.096 chamadas. A tabela fixa ficou local durante todo o teste, sem conversões. Esse resultado justificou concentrar C++ somente na expansão G.711.

**Tipos e containers.** A versão instalada infere locais numéricos nativos. No código gerado, o comprimento, a sequência e o espaço restante são inteiros nativos e as chamadas entre receptor, buffer e decodificador têm símbolos C++ diretos. Qualificar funções como `\strlen` também evita a resolução de funções do namespace. O limite privado do buffer usa um inteiro tipado. Os campos públicos de pacotes conservam `readonly`. `std::array`, `std::vector`, `std::map` e `std::orderedMap` estão documentados nessa versão. Um vetor por chamada reservaria armazenamento para uma fila normalmente vazia. Os mapas teriam custo de manutenção e precisariam preservar o flush em ordem. Não houve ganho demonstrado que justificasse mudar essa fila ou converter containers para arrays Zend. [Tipos nativos](https://github.com/swoole/typephp/blob/v0.10.1/docs/en/NATIVE_TYPES.md) · [Containers](https://github.com/swoole/typephp/blob/v0.10.1/docs/en/STD_CONTAINERS.md)

O compilador instalado reconhece `#[Native]`, `#[Hot]` e `#[Cold]`. Classes nativas têm modelo próprio de armazenamento e exposição ao Zend. O caminho comum já evita construir o pacote, enquanto sockets, callbacks e os pacotes pendentes precisam de interoperabilidade. As anotações de frequência não tiveram benefício medido neste trabalho. A CLI instalada aceita níveis 0 a 3, LTO e seleção de arquitetura. A comparação variou apenas o nível 2 ou 3 e manteve a portabilidade do binário. [Modelo de objetos nativos](https://github.com/swoole/typephp/blob/v0.10.1/docs/en/NATIVE_CLASS_OBJECT.md) · [CLI](https://github.com/swoole/typephp/blob/v0.10.1/docs/en/COMPILER_CLI.md)

**Áudio.** O limite configurável continua sendo de PCM por gravação, arredondado para amostras completas. A cauda cresce até ultrapassar o alvo de 16 KiB e então vira um bloco. `saveRecordingToWavFile()` escreve o cabeçalho, os blocos e a cauda de um snapshot por valor. `getBuffer()` continua disponível para quem precisa de uma string contígua. Com 64.000.000 bytes de PCM, exportar via `pcm()` alocou 64.041.040 bytes adicionais no pico do alocador PHP. A escrita segmentada alocou 768 bytes adicionais. Os picos de RSS foram 148.536 e 85.988 KiB, respectivamente, em processos separados. Os WAVs tiveram o mesmo SHA-256, `8ba242b9ee6d75d3365e3765b37524e7582d8fc5e4f1427efdfc0bb67ec94c66`. O armazenamento de áudio continua custando 16.000 bytes/s por chamada até o limite configurado.

**Escalonamento.** O experimento `MODE=burst` enfileira 128 pacotes por vez em um único socket e observa o intervalo de despertar de uma corrotina que pede 1 ms. Com quanta de 32, 64 e 128 datagramas, as medianas de três execuções foram 18.481, 29.123 e 59.957 pacotes/s. As medianas dos P95 de despertar foram 2,384, 3,005 e 2,661 ms. Mantivemos 32 pela menor latência P95 nesse teste. O ensaio mede responsividade do escalonador com um socket saturado. Não estabelece um quantum ótimo para toda carga SIP.

**SIP.** O primeiro envio reutiliza a serialização já armazenada para retransmissões. O UA conserva o lookup por Call-ID e branch/método, a verificação da identidade da transação e um loop compartilhado para prazos. Os callbacks continuam em ordem fora da corrotina de recepção SIP. Os limites de chamadas e transações agora podem ser dimensionados no construtor, com os padrões anteriores de 64 e 512. As suítes verificam admissão, rejeição e reuso de capacidade, REGISTER com autenticação, INVITE, ACK, early media, CANCEL, BYE, timeout e liberação das portas. Uma execução AOT estabeleceu 1.000 chamadas antes de enviar dois pacotes RTP individualizados por chamada, conferir os 1.000 buffers e encerrar os diálogos.

**Trabalho futuro.** Os custos que ainda merecem medição são a alocação da recepção pelo Swoole, a entrega dos metadados de origem e as varreduras de transações e respostas SIP em grande volume de sinalização. Uma API de recepção em lote com peer por datagrama ou um adaptador nativo precisaria demonstrar ganho sobre o loop atual incluindo custo do sistema operacional, cancelamento cooperativo e latência SIP. O parser completo para pacotes com extensões também pode ser medido separadamente antes de expandir a área de código nativo.

Durante a retenção de transações, os callbacks podem manter referências ao diálogo encerrado até o prazo de expiração. O socket RTP é fechado no encerramento, e quem conserva o objeto da chamada ainda consegue exportar o buffer. O dimensionamento de memória deve considerar gravações retidas. Mil chamadas que atinjam o limite padrão acumulam 4,8 GB só de PCM.

## Arquivos da revisão

- Recepção, parsing e reordenação em [MediaSession.php](../src/Media/MediaSession.php), [RtpReceiver.php](../src/Media/RtpReceiver.php) e [RtpPacket.php](../src/Media/RtpPacket.php).
- Decodificação em [G711Decoder.php](../src/Media/G711Decoder.php), [g711.cc](../src/Media/g711.cc) e [g711.stub.php](../src/Media/g711.stub.php).
- Gravação e API da chamada em [AudioBuffer.php](../src/Media/AudioBuffer.php), [WavWriter.php](../src/Media/WavWriter.php) e [SipCall.php](../src/Sip/SipCall.php).
- Admissão e serialização SIP em [SipUserAgent.php](../src/Sip/SipUserAgent.php) e [SipTransaction.php](../src/Sip/SipTransaction.php).
- Verificação em [tests/media.php](../tests/media.php), [tests/signaling.php](../tests/signaling.php) e [test-aot.py](test-aot.py).
- Medições em [media.php](media.php), [media.yml](media.yml), [experiments.php](experiments.php), [experiments.yml](experiments.yml), [run.sh](run.sh), [compare.sh](compare.sh) e [results.csv](results.csv).
- Documentação neste relatório e no [README da biblioteca](../README.md).
