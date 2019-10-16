<?php

namespace App\Domain\Fixture\Command;

use App\Domain\Fixture\SqlToCsv;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class ExtractAttributes
{
    /** @var \PDO */
    private $pdo;
    /** @var Environment */
    private $twig;

    public function __construct(\PDO $pdo, Environment $twig)
    {
        $this->pdo = $pdo;
        $this->twig = $twig;
    }

    public function __invoke(\SplFileObject $output, array $attributes, array $locales): void
    {
        try {
            $view = $this->twig->load('extract-attributes.sql.twig');
        } catch (LoaderError|RuntimeError|SyntaxError $e) {
            throw new \RuntimeException(null, null, $e);
        }

        (new SqlToCsv($this->pdo))
        (
            $view->render([
                'attributes' => $attributes,
                'locales' => $locales,
            ]),
            $output
        );
    }
}