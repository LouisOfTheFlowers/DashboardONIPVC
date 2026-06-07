# Dashboard ON.IPVC

Aplicação web para visualizar dashboards de alertas do ON.IPVC. O projeto usa ficheiros JSON exemplo como fonte de dados e permite configurar cores/intervalos de alerta através de uma página propria de configuração.

## O Que A Aplicação Faz

A aplicação mostra dashboards diferentes para perfis diferentes:

- Presidência
- Coordenador de Curso
- Docente
- Direção UO
- Pessoal
- Gestão Documental
- GAC

Cada dashboard mostra informação como aulas por lecionar, sumários por publicar, estados de PUCs/RUCs, pedidos de substituição, estágios, tarefas, documentos, datas de validade e outros alertas.

## Tecnologias Usadas

- PHP: renderização das dashboards e API da configuração.
- JSON: dados das dashboards e ficheiros de configuração.
- JavaScript: interatividade das dashboards, incluindo drag-and-drop.
- Svelte: página de configuração dos alertas.
- Vite: build da aplicação Svelte.

## Requisitos

Para correr o projeto localmente precisas de:

- PHP instalado e disponivel no terminal.
- Node.js e npm, apenas para alterar/recompilar a página de configuração.

Verificar instalação:

```powershell
php -v
node -v
npm -v
```

## Como Correr O Projeto

Na pasta do projeto, correr:

```powershell
php -S localhost:8000
```

Depois abrir no browser:

```text
http://localhost:8000
```

Tambem se pode abrir diretamente uma dashboard:

```text
http://localhost:8000/presidencia.php
http://localhost:8000/cc.php
http://localhost:8000/docente.php
http://localhost:8000/dir_uo.php
http://localhost:8000/pessoal.php
http://localhost:8000/gestao_documental.php
http://localhost:8000/gac.php
```

Outra forma e usar o `index.php` com o parâmetro `profile`:

```text
http://localhost:8000/index.php?profile=presidencia
http://localhost:8000/index.php?profile=cc
http://localhost:8000/index.php?profile=docente
http://localhost:8000/index.php?profile=dir_uo
http://localhost:8000/index.php?profile=pessoal
http://localhost:8000/index.php?profile=gestao_documental
http://localhost:8000/index.php?profile=gac
```

## Como Usar As Dashboards

1. Abre uma das páginas da dashboard.
2. Usa os separadores no topo para mudar entre perfis.
3. Quando existirem filtros, escolhe o semestre ou a unidade orgânica pretendida.
4. Lê os cards e tabelas apresentados.
5. As cores dos numeros indicam o nivel de alerta configurado.

## Personalização Das Dashboards

As dashboards permitem reorganizar blocos por drag-and-drop:

1. Mantem o rato pressionado sobre um bloco.
2. Arrasta o bloco para a posição pretendida.
3. Larga o bloco.

A ordem fica guardada no browser atraves de `localStorage`.

Para voltar a ordem original, clica em:

```text
Repor ordem
```

Nota: a personalização fica guardada apenas no browser onde foi feita.

## Pagina De Configuração

A pagina de configuração permite alterar os intervalos e cores sem editar manualmente os ficheiros JSON.

Abrir:

```text
http://localhost:8000/configuracao.php
```

Nesta pagina podes:

- escolher o ficheiro de configuração;
- alterar intervalos de alerta;
- alterar cores globais;
- editar diretamente o JSON;
- guardar as alterações.

Depois de guardar, as dashboards passam a usar os novos valores.

## Como Funcionam Os Ficheiros JSON

Existem dois tipos principais de JSON.

### Dados Das Dashboards

Ficam na pasta:

```text
alertas/
```

Exemplos:

- `alertsPresidencia.json`
- `alertsCC.json`
- `alertsDocente.json`
- `alertsDirUO.json`
- `alertsFuncGeral.json`
- `alertsSA.json`

Estes ficheiros contem os dados mostrados nas dashboards.

### Configuracao Dos Alertas

Ficam na pasta:

```text
alertasconfig/
```

Exemplos:

- `alertsPresidenciaconfig.json`
- `alertsCCconfig.json`
- `alertsDocenteconfig.json`
- `alertsDirUOconfig.json`
- `alertsFuncGeralconfig.json`
- `alertsSAconfig.json`

Estes ficheiros definem regras de apresentação, como:

- intervalos numéricos;
- cores dos alertas;
- labels;
- configuração de cards;
- configuração de tabelas;
- intervalos por dias para datas de validade.

## Exemplos De Configuracao

Um intervalo numerico pode ter esta estrutura:

```json
[
  {
    "min": 0,
    "max": 5,
    "color": "success"
  },
  {
    "min": 6,
    "max": 20,
    "color": "warning"
  },
  {
    "min": 21,
    "max": 9999,
    "color": "critical"
  }
]
```

Um intervalo de datas usa dias restantes:

```json
[
  {
    "min_days": -99999,
    "max_days": -1,
    "color": "critical",
    "label": "Expirado"
  },
  {
    "min_days": 0,
    "max_days": 60,
    "color": "warning",
    "label": "Expira em breve"
  },
  {
    "min_days": 61,
    "max_days": 99999,
    "color": "success",
    "label": "Valido"
  }
]
```

Neste exemplo:

- datas passadas ficam vermelhas;
- datas até 60 dias ficam amarelas;
- datas com mais de 60 dias ficam verdes.

## Estrutura Do Projeto

```text
.
├── dashboard.php
├── index.php
├── presidencia.php
├── cc.php
├── docente.php
├── dir_uo.php
├── pessoal.php
├── gestao_documental.php
├── gac.php
├── configuracao.php
├── config-api.php
├── alertas/
├── alertasconfig/
├── config-editor/
├── configuracao-assets/
├── package.json
└── vite.config.js
```

### Ficheiros Principais

`dashboard.php`

Ficheiro principal das dashboards. Lê os dados, aplica a configuração e renderiza a interface.

`index.php`

Permite abrir dashboards através do parâmetro `profile`.

`presidencia.php`, `cc.php`, `docente.php`, `dir_uo.php`, `pessoal.php`, `gestao_documental.php`, `gac.php`

Paginas diretas para cada dashboard.

`configuracao.php`

Pagina que carrega o editor de configuração.

`config-api.php`

API usada pelo editor para ler e guardar ficheiros em `alertasconfig/`.

`config-editor/`

Código fonte da página de configuração em Svelte.

`configuracao-assets/`

Versão compilada da página de configuração. E esta pasta que o PHP carrega no browser.

## Alterar A Pagina De Configuracao

Se alterares ficheiros dentro de:

```text
config-editor/
```

tens de recompilar:

```powershell
npm run build
```

Isto atualiza:

```text
configuracao-assets/
```

Sem este comando, o browser pode continuar a usar a versão antiga.

## Comandos Uteis

Validar PHP:

```powershell
Get-ChildItem -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Validar JSON:

```powershell
php -r "foreach (array_merge(glob('alertas/*.json'), glob('alertasconfig/*.json')) as $f) { json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR); echo $f, PHP_EOL; }"
```

Compilar a página de configuração:

```powershell
npm run build
```

## Problemas Comuns

### `php` Não é Reconhecido

Significa que o PHP não esta instalado ou não esta no `PATH`.

Depois de instalar PHP, fecha e volta a abrir o terminal antes de correr:

```powershell
php -v
```

### Alterei O Svelte Mas Nada Mudou

Corre:

```powershell
npm run build
```

Depois atualiza a página no browser.

### A Dashboard Diz Que Falta Um JSON

Confirma se o ficheiro existe na pasta:

```text
alertas/
```

E confirma se estas a correr o servidor PHP na raiz do projeto.

## Estado Atual

O projeto esta preparado para correr localmente com PHP. A página de configuração permite alterar regras de alerta sem editar diretamente o código. As dashboards usam dados JSON e configurações separadas por perfil.
