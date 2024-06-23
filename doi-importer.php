<?php

/**
 * @file doi-importer.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 */

/**
 * Extracts DOIs from a Native XML file and generates a SQL script to import it into the database
 */
class DoiImporter {
    private DOMDocument $document;
    private DOMXpath $xpath;

    /**
     * Constructor
     * @param string $input Input filename
     * @param string $output Output filename
     */
    public function __construct(private Configuration $configuration)
    {
        $this->document = new DOMDocument('1.0', 'utf-8');
        $this->document->load($this->configuration->input, LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NSCLEAN);
        $this->xpath = new DOMXPath($this->document);
        $this->xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
        $this->xpath->registerNamespace('pkp', 'http://pkp.sfu.ca');

        $this->process();
    }

    /**
     * Processes the XML data
     */
    public function process(): void
    {
        /** @var DOMElement $article */
        foreach ($this->select('//pkp:article') as $i => $article) {
            $publicationId = $article->getAttribute('current_publication_id');
            /** @var DOMElement */
            $publication = $this->selectFirst("pkp:publication/pkp:id[text() = '{$publicationId}']/..", $article);
            if (!$publication) {
                fwrite(STDERR, "The article {$i} has no publications");
            }

            $doi = $this->selectFirst("pkp:id[@type = 'doi']", $publication)?->textContent;
            if (!$doi) {
                fwrite(STDERR, "The article {$i} has no DOI");
            }

            $titles = [];
            /** @var DOMElement $title */
            foreach ($this->select("pkp:title", $publication) as $title) {
                $titles[] = "'" . addslashes($title->textContent) . "'";
            }

            $doi = addslashes($doi);
            $titles = implode(', ', $titles);
            $sql = "INSERT INTO publication_settings (publication_id, setting_name, setting_value, locale)"
                . "\nSELECT (SELECT DISTINCT ps.publication_id FROM publication_settings ps WHERE ps.setting_name = 'title' AND ps.setting_value IN ({$titles})) AS publication_id, 'pub-id::doi' AS setting_name, '{$doi}' AS setting_value, '' AS locale;\n\n";

            file_put_contents($this->configuration->output, $sql, FILE_APPEND);
        }
    }

    /**
     * Evaluates and retrieves the given XPath expression
     *
     * @param string $path XPath expression
     * @param DOMNode $context Optional context node
     */
    private function evaluate(string $path, ?DOMNode $context = null)
    {
        return $this->xpath->evaluate($path, $context);
    }

    /**
     * Evaluates and retrieves the given XPath expression as a trimmed and tag stripped string
     * The path is expected to target a single node
     *
     * @param string $path XPath expression
     * @param DOMNode $context Optional context node
     */
    private function selectText(string $path, ?DOMNode $context = null): string
    {
        return strip_tags(trim($this->evaluate("string({$path})", $context)));
    }

    /**
     * Retrieves the nodes that match the given XPath expression
     *
     * @param string $path XPath expression
     * @param DOMNode $context Optional context node
     */
    private function select(string $path, ?DOMNode $context = null): DOMNodeList
    {
        return $this->xpath->query($path, $context);
    }

    /**
     * Query the given XPath expression and retrieves the first item
     *
     * @param string $path XPath expression
     * @param DOMNode $context Optional context node
     */
    private function selectFirst(string $path, ?DOMNode $context = null): ?object
    {
        return $this->select($path, $context)->item(0);
    }
}

/**
 * Configuration attributes
 */
class Configuration {
    /**
     * @param ?string $input Input filename
     * @param ?string $output Output filename
     */
    private function __construct(public ?string $input = null, public ?string $output = null) {
    }

    public static function fromCommandLine(): static
    {
        $configuration = new static();
        $options = ['i:' => 'input:', 'o:' => 'output:'];
        $input = getopt(implode('', array_keys($options)), array_values($options));
        foreach ($options as $short => $long) {
            $long = rtrim($long, ':');
            $short = rtrim($short, ':');
            $configuration->$long = $input[$short] ?? $input[$long] ?? null;
        }

        $configuration->input = $configuration->input === '-' ? 'php://stdin' : $configuration->input;
        $configuration->output === '-' ? 'php://stdout' : $configuration->output;

        if(empty($configuration->input) || empty($configuration->output)) {
            $filename = basename(__FILE__);
            echo implode("\n", [
                $filename,
                str_repeat('=', strlen($filename)),
                'Tool to import missing DOIs from a Native XML file into OJS, it uses the article\'s title as a key to match with an existing entry in the database, then generates a SQL script upon completion.',
                '',
                'Usage:',
                "{$filename} -i filename -o filename",
                '',
                'Options:',
                " -i, --input <filename>\t\t\tFilename can be replaced by \"-\" to read from stdin",
                " -o, --output <filename>\t\tThe path where the SQL script will be generated, it can be replaced by \"-\" to write to stdout"
            ]);
            exit -1;
        }
        return $configuration;
    }
}

new DoiImporter(Configuration::fromCommandLine());
