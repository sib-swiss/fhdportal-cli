# FEGA CLI

A command-line interface tool for working with Federated European Genome-phenome Archive (FEGA) submission bundles and metadata validation.

## About FEGA

The **Federated European Genome-phenome Archive (FEGA)** is the primary global resource for discovery and access of sensitive human omics and associated data consented for secondary use, through a network of national human data repositories to accelerate disease research and improve human health.

FEGA collaborates with European and global initiatives including [GA4GH](https://www.ga4gh.org/how-we-work/driver-projects/), [ELIXIR](https://elixir-europe.org/), [1+ Million Genomes Framework](https://framework.onemilliongenomes.eu/), and [GDI](https://gdi.onemilliongenomes.eu/). By providing a solution to emerging challenges around secure and efficient management of human omics and associated data, the FEGA Network fosters data reuse, enables reproducibility, and accelerates biomedical research.

For more information about FEGA, visit: https://ega-archive.org/about/projects-and-funders/federated-ega/

## Installation

### Prerequisites

- PHP 8.2 or higher
- Composer

### Setup

1. Clone the repository:

```bash
git clone <repository-url>
cd fega-cli
```

2. Install dependencies:

```bash
composer install
```

3. Make the console executable:

```bash
chmod +x bin/console
```

## Usage

The FEGA CLI provides four main commands for working with FEGA submission bundles:

```bash
./bin/console <command> [options] [arguments]
```

### Available Commands

- [`bundle`](#bundle-command) - Generate a manifest file for a FEGA submission bundle
- [`template`](#template-command) - Generate TSV file templates for metadata
- [`update`](#update-command) - Update local JSON schemas from the FEGA API
- [`validate`](#validate-command) - Validate FEGA metadata files and submission bundles

---

## Bundle Command

Generate a manifest file for a FEGA submission bundle.

### Syntax

```bash
./bin/console bundle [options] <directory-path>
```

### Arguments

- `directory-path` **(required)** - Directory containing files to bundle

### Options

- `-a, --create-archive` - Create a ZIP archive of the bundle
- `-o, --output-file=OUTPUT-FILE` - Output file path for the archive
- `-w, --overwrite-manifest` - Overwrite manifest file if it already exists
- `-v, --verbose` - Increase verbosity of messages
- `-h, --help` - Display help for the command

### Description

The `bundle` command scans a directory containing FEGA metadata files and generates a `manifest.yaml` file that describes the contents of the submission bundle. The manifest maps each file to its corresponding FEGA resource type based on the filename.

**Supported resource types:**

- `Dataset` (datasets.tsv)
- `File` (files.tsv)
- `MolecularAnalysis` (molecularanalyses.tsv)
- `MolecularExperiment` (molecularexperiments.tsv)
- `MolecularRun` (molecularruns.tsv)
- `Sample` (samples.tsv)
- `Publication` (publications.tsv)
- `SdaFile` (sdafiles.tsv)
- `Study` (studies.tsv)
- `Submission` (submissions.tsv)

### Examples

**Basic usage:**

```bash
./bin/console bundle /path/to/submission/data
```

**Create a bundle with archive:**

```bash
./bin/console bundle -a /path/to/submission/data
```

**Specify custom archive name:**

```bash
./bin/console bundle -a -o my-submission.zip /path/to/submission/data
```

**Overwrite existing manifest:**

```bash
./bin/console bundle -w /path/to/submission/data
```

**Verbose output:**

```bash
./bin/console bundle -v -a /path/to/submission/data
```

---

## Template Command

Generate TSV file templates for FEGA metadata submission.

### Syntax

```bash
./bin/console template [options]
```

### Options

- `-o, --output-file=OUTPUT-FILE` - Output file path for the archive (default: `templates.zip`)
- `-v, --verbose` - Increase verbosity of messages
- `-h, --help` - Display help for the command

### Description

The `template` command generates TSV (Tab-Separated Values) file templates for all available FEGA resource types. These templates contain the correct column headers based on the current JSON schemas, making it easier to prepare metadata files for submission.

The templates are created based on the table schemas retrieved from the local JSON schema files. Each template file is named using the pluralized, snake_case version of the resource type (e.g., `molecular_analyses.tsv` for `MolecularAnalysis`).

### Examples

**Generate templates with default filename:**

```bash
./bin/console template
```

**Specify custom output file:**

```bash
./bin/console template -o my-templates.zip
```

**Verbose output:**

```bash
./bin/console template -v
```

---

## Update Command

Update the local JSON schemas from the FEGA API.

### Syntax

```bash
./bin/console update [options]
```

### Options

- `-v, --verbose` - Increase verbosity of messages
- `-h, --help` - Display help for the command

### Description

The `update` command fetches the latest JSON schemas from the FEGA API and updates the local schema files in the `config/schemas/` directory. This ensures that validation and template generation use the most current schema definitions.

The command performs the following operations:

1. Fetches schemas from the FEGA API endpoint
2. Deletes existing local schema files
3. Creates new schema files with the updated definitions
4. Formats the JSON content for readability

### Examples

**Update schemas:**

```bash
./bin/console update
```

**Update with verbose output:**

```bash
./bin/console update -v
```

---

## Validate Command

Validate FEGA metadata files and submission bundles.

### Syntax

```bash
./bin/console validate [options] <target-path>
```

### Arguments

- `target-path` **(required)** - File or directory path to validate

### Options

- `-t, --resource-type=RESOURCE-TYPE` - Type of the resource (default: `SubmissionBundle`)
- `-f, --output-format=OUTPUT-FORMAT` - Output format: `json` or `text` (default: `text`)
- `-v, --verbose` - Increase verbosity of messages
- `-h, --help` - Display help for the command

### Description

The `validate` command performs comprehensive validation of FEGA metadata files and submission bundles against the JSON schemas. It supports various input formats and provides detailed error reporting.

**Supported input types:**

- **Directories** - Validates as submission bundles
- **ZIP archives** - Extracts and validates as submission bundles
- **TSV/CSV files** - Validates individual metadata files
- **JSON files** - Validates individual resource files

**Validation features:**

- Schema validation against FEGA JSON schemas
- Manifest file validation
- Cross-referential integrity checks
- Detailed error reporting with line numbers
- Error grouping for similar issues

### Examples

**Validate a submission bundle directory:**

```bash
./bin/console validate /path/to/submission/bundle
```

**Validate a ZIP archive:**

```bash
./bin/console validate submission-bundle.zip
```

**Validate a TSV file with specific resource type:**

```bash
./bin/console validate -t Dataset datasets.tsv
```

**Validate with JSON output:**

```bash
./bin/console validate -f json /path/to/bundle
```

**Validate with verbose output:**

```bash
./bin/console validate -v /path/to/bundle
```

### Resource Types

The following resource types are supported for validation:

- `Dataset`
- `File`
- `MolecularAnalysis`
- `MolecularExperiment`
- `MolecularRun`
- `Publication`
- `Sample`
- `SdaFile`
- `Study`
- `Submission`
- `SubmissionBundle` (default)

### Output Formats

**Text Format (default):**

- Human-readable output with color coding
- Grouped error reporting
- Line number references
- Success/failure summaries

**JSON Format:**

- Machine-readable structured output
- Complete validation results
- Suitable for integration with other tools

---

## Configuration

The CLI tool uses configuration files located in the `config/` directory:

- `config/schemas/` - JSON schema files for validation
- `config/services.yaml` - Service configuration
- `config/packages/` - Framework configuration

## Error Handling

The CLI provides comprehensive error handling and reporting:

- **Validation errors** are grouped by similarity and displayed with line numbers
- **File errors** include specific details about missing or malformed files
- **Schema errors** highlight specific validation rule violations
- **System errors** provide clear guidance for resolution

## Exit Codes

- `0` - Success
- `1` - General failure
- `2` - Invalid arguments or options

## Development

### Requirements

- PHP 8.2+
- Symfony 7.2+
- Composer

### Key Dependencies

- `symfony/console` - Command-line interface framework
- `symfony/validator` - Data validation
- `opis/json-schema` - JSON schema validation
- `symfony/yaml` - YAML file processing
- `symfony/http-client` - HTTP API communication
