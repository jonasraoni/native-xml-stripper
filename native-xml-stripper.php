<?php

/**
 * @file native-xml-stripper.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 */

/**
 * Filters out non-published data from the Native XML format
 */
class NativeXmlFilter {
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
        $data = $this->configuration->instructions
            ? json_decode(file_exists($this->configuration->data) ? file_get_contents($this->configuration->data) : '', false) ?? new stdClass()
            : null;
        if ($data) {
            $data->locales = array_fill_keys($data->locales ?? [], null);
            $data->genres = array_fill_keys($data->genres ?? [], null);
        }

        /** @var DOMElement $article */
        foreach ($this->select('//pkp:article') as $article) {
            $publicationId = $article->getAttribute('current_publication_id');
            /** @var DOMElement */
            $currentPublication = $this->selectFirst("pkp:publication/pkp:id[text() = '{$publicationId}']/..", $article);
            if (!$currentPublication) {
                throw new Exception('This article has no publications');
            }

            foreach ($this->select('pkp:publication', $article) as $publication) {
                if ($publication !== $currentPublication) {
                    $publication->remove();
                }
            }

            $submissionFileIds = [];
            foreach ($this->select('pkp:article_galley/pkp:submission_file_ref/@id', $currentPublication) as $id) {
                $submissionFileIds[] = $id->nodeValue;
            }

            foreach ($this->select('pkp:submission_file', $article) as $submissionFile) {
                if (($ownerSubmissionFileId = $this->selectFirst('pkp:submission_file_ref/@id', $submissionFile))) {
                    if (!in_array($ownerSubmissionFileId->nodeValue, $submissionFileIds)) {
                        $this->selectFirst('../..', $ownerSubmissionFileId)->remove();
                        continue;
                    }
                } elseif (!in_array($submissionFile->getAttribute('id'), $submissionFileIds)) {
                    $submissionFile->remove();
                    continue;
                }

                if ($this->configuration->uploader) {
                    $submissionFile->setAttribute('uploader', $this->configuration->uploader);
                }

                if ($data) {
                    $data->genres ??= [];
                    $data->genres[$submissionFile->getAttribute('genre')] = null;
                }

                $fileId = $submissionFile->getAttribute('file_id');
                $currentFile = $this->selectFirst("pkp:file[@id = {$fileId}]", $submissionFile);
                if (!$currentFile) {
                    throw new Exception('The <file> entry with ID  was not found');
                }

                foreach ($this->select('pkp:file', $submissionFile) as $file) {
                    if ($file !== $currentFile) {
                        $file->remove();
                    }
                }
            }

            if ($this->configuration->authorUserGroup) {
                foreach ($this->select('/pkp:authors/pkp:author/@user_group_ref', $currentPublication) as $authorUserGroup) {
                    $authorUserGroup->textContent = $this->configuration->authorUserGroup;
                }
            }

            if ($data) {
                foreach ($this->select('//@locale') as $locale) {
                    $data->locales[$locale->nodeValue] = null;
                }
                $locales = array_keys($data->locales);
                $genres = array_keys($data->genres);
                $genreList = "SELECT '" . implode("' AS genre UNION ALL SELECT '", array_map(fn (string $genre) => addcslashes($genre, "'\\"), $genres)) . "' AS genre";
                file_put_contents($this->configuration->instructions, implode("\n", [
                    '#1. Ensure the following locales are enabled in the journal:',
                    implode(', ', $locales),
                    '',
                    '#2. Run the query below, it will ensure the required genres exist:',
                    '```sql',
                    implode("\n", array_map('trim', explode("\n", "CREATE TEMPORARY TABLE imported_genres AS
                    SELECT
                        j.journal_id AS context_id,
                        j.primary_locale AS locale,
                        imported.genre AS name,
                        (
                            SELECT MAX(g.seq)
                            FROM genres g
                            WHERE g.context_id = j.journal_id
                        ) + ROW_NUMBER() OVER (ORDER BY imported.genre) AS seq
                    FROM ({$genreList}) AS imported
                    INNER JOIN journals j ON j.path = '{$this->configuration->journal}'
                    LEFT JOIN genre_settings gs ON gs.setting_name = 'name' AND gs.setting_value = imported.genre
                    LEFT JOIN genres g ON j.journal_id = g.context_id AND gs.genre_id = g.genre_id
                    WHERE g.genre_id IS NULL;

                    INSERT INTO genres (context_id, seq)
                    SELECT ig.context_id, ig.seq
                    FROM imported_genres ig;

                    INSERT INTO genre_settings (genre_id, locale, setting_name, setting_value, setting_type)
                    SELECT g.genre_id, ig.locale, 'name', ig.name, 'string'
                    FROM imported_genres ig
                    INNER JOIN genres g ON g.context_id = ig.context_id AND g.seq = ig.seq;

                    DROP TABLE imported_genres;"))),
                    '```',
                    '',
                    '#3. Import the stripped XMLs.',
                ]));

                file_put_contents($this->configuration->data, json_encode([
                    'locales' => $locales,
                    'genres' => $genres
                ], JSON_PRETTY_PRINT));
            }
        }
        $this->document->save($this->configuration->output);
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
     * @param ?string $uploader The username which will be assigned to the uploader attribute of files
     * @param ?string $authorUserGroup The username which will be assigned to authors
     * @param ?string $instructions The filename where the file with import instructions will be generated
     * @param ?string $journal The destination journal path
     * @param ?string $data The accumulator file path
     */
    private function __construct(public ?string $input = null, public ?string $output = null, public ?string $uploader = null, public ?string $authorUserGroup = null, public ?string $instructions = null, public ?string $journal = null, public ?string $data = 'data.json') {
    }

    public static function fromCommandLine(): static
    {
        $configuration = new static();
        $options = ['i:' => 'input:', 'o:' => 'output:', 'u:' => 'uploader:', 'a:' => 'authorUserGroup:', 'h:' => 'instructions:', 'j:' => 'journal:'];
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
                'Removes non-public data from Native XML files produced by PKP software. It processes XML data from a file or stdin and outputs the content to another file or stdout',
                '',
                'Usage:',
                "{$filename} -i filename -o filename [options]",
                '',
                'Options:',
                " -i, --input <filename>\t\t\tFilename can be replaced by \"-\" to read from stdin",
                " -o, --output <filename>\t\tFilename can be replaced by \"-\" to write to stdout",
                " -u, --uploader <username>\t\tUsername of the uploader, if absent the current value will be kept",
                " -a, --authorUserGroup <userGroup>\tThe author user group, if absent the current value will be kept",
                " -h, --instructions <filename>\t\tThe path where the pre-import instructions and notes will be generated",
                " -j, --journal <journalPath>\t\tThe path of the destination journal, it will be used to generate the database scripts",
            ]);
            exit -1;
        }
        return $configuration;
    }
}

new NativeXmlFilter(Configuration::fromCommandLine());
