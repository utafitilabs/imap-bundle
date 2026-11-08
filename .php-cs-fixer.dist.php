<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'header_comment' => ['header' => <<<'EOF'
This file is part of the UtafitiLabs IMAP Bundle.

(c) Ezekiel Mjema <https://github.com/eemjema>

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF],
    ])
    ->setFinder($finder);
