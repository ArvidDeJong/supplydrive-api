<?php

declare(strict_types=1);

/**
 * Guards the rules of the GitHub Pages site in docs/: front matter, Liquid use,
 * single-source facts and the developer credit. Nothing here breaks loudly otherwise.
 */
function docsPath(string $path = ''): string
{
    return dirname(__DIR__).'/docs'.($path === '' ? '' : '/'.$path);
}

/**
 * @return array<string, string>
 */
function frontMatter(string $file): array
{
    preg_match('/\A---\n(.*?)\n---\n/s', (string) file_get_contents($file), $match);

    $values = [];
    foreach (explode("\n", $match[1] ?? '') as $line) {
        if (preg_match('/^(\w+):\s*(.*)$/', $line, $pair)) {
            $value = trim($pair[2]);

            // An unquoted ": " makes the YAML invalid, and Jekyll then silently ignores all front matter.
            expect(str_contains($value, ': ') && ! str_starts_with($value, '"'))
                ->toBeFalse(basename($file).': quote the value of '.$pair[1]);

            $values[$pair[1]] = trim($value, '"');
        }
    }

    return $values;
}

test('every page has a title, a unique description and a unique nav order', function () {
    $pages = glob(docsPath('*.md'));
    $descriptions = [];
    $navOrders = [];

    foreach ($pages as $page) {
        $meta = frontMatter($page);

        expect($meta)->toHaveKeys(['title', 'description', 'nav_order'], basename($page));
        $descriptions[] = $meta['description'];
        $navOrders[] = $meta['nav_order'];
    }

    expect(count($pages))->toBeGreaterThan(5);
    expect(array_unique($descriptions))->toHaveCount(count($pages));
    expect(array_unique($navOrders))->toHaveCount(count($pages));
});

test('Blade examples are wrapped in raw, so Liquid does not eat them', function () {
    foreach (glob(docsPath('*.md')) as $page) {
        $name = basename($page);

        if ($name === 'faq.md') {
            continue;
        }

        $body = (string) file_get_contents($page);

        // Front matter is YAML, not Liquid.
        $body = (string) preg_replace('/\A---\n.*?\n---\n/s', '', $body);

        $opens = substr_count($body, '{% raw %}');
        $closes = substr_count($body, '{% endraw %}');

        expect($opens)->toBe($closes, $name.': every {% raw %} needs its {% endraw %}');
        expect(substr_count($body, '{%'))->toBe($opens + $closes, $name.': the only Liquid tags allowed here are raw and endraw');

        // Strip the raw blocks; whatever Liquid is left would be rendered away.
        $stripped = (string) preg_replace('/\{% raw %\}.*?\{% endraw %\}/s', '', $body);

        // Not ->not->toContain($needle, $message): toContain() reads a second argument as another
        // needle, and the negated check then passes whatever the page holds.
        expect(str_contains($stripped, '{{'))
            ->toBeFalse($name.': wrap this Blade in {% raw %} ... {% endraw %}');
    }
});

test('inline scripts survive the theme compressing each page to one line', function () {
    $pages = glob(docsPath('*.md'));
    expect($pages)->not->toBeEmpty();

    foreach ($pages as $page) {
        preg_match_all('/<script>(.*?)<\/script>/s', (string) file_get_contents($page), $scripts);

        foreach ($scripts[1] as $script) {
            expect($script)->not->toMatch('/^\s*\/\//m', basename($page).': use /* */ instead of // comments');
        }
    }
});

test('the FAQ, structured data and llms.txt read from the shared data', function () {
    $faq = (string) file_get_contents(docsPath('_data/faq.yml'));

    expect(substr_count($faq, '- q: '))->toBeGreaterThanOrEqual(6);
    expect(substr_count($faq, '- q: '))->toBe(substr_count($faq, '  a: '));

    expect(file_get_contents(docsPath('faq.md')))->toContain('site.data.faq');
    expect(file_get_contents(docsPath('llms.txt')))
        ->toContain('permalink: /llms.txt')
        ->toContain('site.data.faq')
        ->toContain('site.pages');
    expect(file_get_contents(docsPath('_includes/head_custom.html')))
        ->toContain('"FAQPage"')
        ->toContain('"SoftwareSourceCode"')
        ->toContain('site.data.faq');
});

test('the YAML files build, because one bad value fails the whole Pages build', function () {
    // An unquoted ": " makes the YAML invalid. Jekyll then aborts the build and GitHub keeps
    // serving the last version that did build, so the site looks fine while it is months old.
    foreach (['_config.yml', '_data/faq.yml'] as $file) {
        $path = docsPath($file);

        if (! is_file($path)) {
            continue;
        }

        $blockIndent = null;

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $number => $line) {
            $indent = strlen($line) - strlen(ltrim($line));

            if ($blockIndent !== null) {
                // Inside a > or | block every line is text, whatever it contains.
                if (trim($line) === '' || $indent > $blockIndent) {
                    continue;
                }

                $blockIndent = null;
            }

            if (! preg_match('/^(\s*)(?:-\s+)?(\w+):(?:\s+(\S.*))?$/', $line, $pair)) {
                continue;
            }

            $value = trim($pair[3] ?? '');

            if ($value === '') {
                continue;
            }

            if (str_starts_with($value, '>') || str_starts_with($value, '|')) {
                $blockIndent = strlen($pair[1]);

                continue;
            }

            $quoted = str_starts_with($value, '"')
                || str_starts_with($value, "'")
                || str_starts_with($value, '[');

            expect(str_contains($value, ': ') && ! $quoted)
                ->toBeFalse($file.' line '.($number + 1).': quote the value of '.$pair[2]);
        }
    }
});

test('every link to the source points at GitHub, because the site has no src directory', function () {
    foreach (glob(docsPath('*.md')) as $page) {
        expect(str_contains((string) file_get_contents($page), '](../'))
            ->toBeFalse(basename($page).': link to the file on GitHub, a relative path leaves the site');
    }
});

test('the config holds the package facts and the sitemap plugin', function () {
    expect(file_get_contents(docsPath('_config.yml')))
        ->toContain('- jekyll-sitemap')
        ->toContain('name: darvis/supplydrive-api')
        ->toContain('baseurl: /supplydrive-api')
        ->toContain('company: ARVID.NL')
        ->toContain('url: https://arvid.nl')
        ->not->toContain('footer_content');
});

test('the footer credits ARVID.NL without a personal name', function () {
    $footer = (string) file_get_contents(docsPath('_includes/footer_custom.html'));

    expect($footer)->toContain('site.developer.company')
        ->toContain('site.developer.url')
        ->not->toContain('Arvid de Jong')
        ->not->toMatch('/developed by|made by/i');

    expect(file_get_contents(docsPath('_includes/head_custom.html')))->not->toContain('"Person"');
});

test('every description is short enough to be shown whole as a search result', function () {
    foreach (glob(docsPath('*.md')) as $page) {
        $length = mb_strlen(frontMatter($page)['description']);

        expect($length)->toBeGreaterThanOrEqual(110, basename($page).': the description says too little')
            ->and($length)->toBeLessThanOrEqual(160, basename($page).': the description gets cut off');
    }
});

test('the FAQ stays between six and ten questions', function () {
    $questions = substr_count((string) file_get_contents(docsPath('_data/faq.yml')), '- q: ');

    expect($questions)->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(10);
});

test('the installation page ends with a way to check that it works', function () {
    expect(file_get_contents(docsPath('installation.md')))
        ->toContain('## Check that it works')
        ->toContain('php artisan supplydrive:status')
        ->toContain('Connection successful');
});

test('every page is linked from the home page, and every relative link points at a page', function () {
    $pages = array_map('basename', glob(docsPath('*.md')));
    $index = (string) file_get_contents(docsPath('index.md'));

    foreach ($pages as $page) {
        if ($page !== 'index.md') {
            expect(str_contains($index, ']('.$page.')'))->toBeTrue($page.' is not linked from index.md');
        }

        preg_match_all('/\]\(([a-z0-9-]+\.md)(#[a-z0-9-]+)?\)/', (string) file_get_contents(docsPath($page)), $links);

        foreach ($links[1] as $target) {
            expect(in_array($target, $pages, true))->toBeTrue($page.' links to '.$target.', which does not exist');
        }
    }
});

test('the messages the docs quote exist in the source', function () {
    $source = '';

    foreach (['SupplydriveClient.php', 'Console/Commands/MessageStatusCommand.php'] as $file) {
        $source .= file_get_contents(dirname(__DIR__).'/src/'.$file);
    }

    foreach ([
        'Supplydrive API config is incomplete (username, password).',
        'Failed to retrieve access_token from Supplydrive.',
        'Supplydrive token response does not contain access_token.',
        'Supplydrive API call failed.',
        'Supplydrive token cache is not available, the token is kept in memory only',
        'At least one Supplydrive filter is required',
        'Unknown Supplydrive filter',
        'Give at least one filter: --functional-id, --message-id, --kvk, --gln or --vat.',
        'Connection successful, no messages match the filters.',
        'Request failed: ',
        'Looking up messages at Supplydrive...',
    ] as $message) {
        expect($source)->toContain($message);
        expect(file_get_contents(docsPath('troubleshooting.md')).file_get_contents(docsPath('installation.md')))->toContain(rtrim($message, ': '));
    }
});
