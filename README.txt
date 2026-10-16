PAINEL DE CAIXA - ERP RESULTH
=============================

Arquivos:
- index.php
- config.php

1. Copie a pasta para o Apache/XAMPP, por exemplo:
   C:\xampp\htdocs\caixa

2. Edite config.php e informe:
   - caminho do RESULTH.FB
   - usuário Firebird
   - senha Firebird
   - charset
   - empresa padrão

3. No PHP, habilite o PDO Firebird.
   No php.ini, confirme a extensão pdo_firebird.
   Depois reinicie o Apache.

4. Abra no navegador:
   http://localhost/caixa/

FILTROS DISPONÍVEIS
-------------------
- Data inicial
- Data final
- Empresa
- Usuário ativo: lista carregada de OPERADOR (ATIVO = S)
- Tipo: Todos / PV / RC
- Forma de pagamento: Todos / Dinheiro / Cartão / Receber / Cheque / Crédito / PIX / Banco
- Cliente: código ou nome

REGRAS UTILIZADAS
-----------------
PV - Vendas:
- PEDIDOC com TIPOPEDIDO = 55 e FATURADO = S
- Dinheiro: DAVPV_DINHEIRO
- Cartão: DAVPV_CARTAO
- Receber: DAVPV_RECEBER
- Cheque: DAVPV_CHEQUE
- Crédito: DAVPV_CREDITO
- PIX: PAGAMENTODIGITAL
- Banco: DAVPV_DEPOSITO

RC - Recebimentos:
- MOVIREC com TIPOMOV = 04 e não estornado
- 01 Dinheiro -> DINHEIRO
- 02 Cheque à vista -> CHEQUE
- 03 Boleto bancário -> BANCO
- 04 Cheque a prazo -> CHEQUE
- 05 Depósito -> BANCO
- 06 PIX -> BANCO (regra definida para este painel)
- 07 Cartão crédito -> CARTÃO
- 08 Cartão débito -> CARTÃO
- 09 Débito em conta -> BANCO

OBSERVAÇÃO
----------
O painel executa apenas SELECTs e usa consultas preparadas para os filtros.


FILTRO POR FORMA DE PAGAMENTO
-----------------------------
Ao escolher uma forma, o painel mostra somente registros em que aquela coluna possui valor maior que zero.
No RC, PIX (forma 06) continua classificado como BANCO conforme regra definida para o painel.

IMPRESSÃO
---------
O botão "Imprimir resultado" abre a impressão do navegador.
Na impressão:
- filtros e botões são ocultados;
- os filtros aplicados aparecem no cabeçalho;
- totalizadores e tabela são impressos;
- a página usa orientação paisagem para acomodar as colunas.
