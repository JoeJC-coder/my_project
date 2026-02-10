<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\String\UnicodeString;

#[AsCommand(
    name: 'app:generate:entities',
    description: 'Generate PHP entities from a YAML definition file'
)]
class GenerateEntitiesFromYamlCommand extends Command
{
    public function __construct(
        private readonly Filesystem $fs,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'yaml',
            InputArgument::OPTIONAL,
            'Path to YAML file (relative to project dir or absolute)',
            'config/codegen/entities.yaml'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $yamlPath = (string) $input->getArgument('yaml');
        $fullPath = $this->resolvePath($yamlPath);

        if (!$this->fs->exists($fullPath)) {
            $io->error(sprintf('YAML file not found: %s', $fullPath));
            return Command::FAILURE;
        }

        $data = Yaml::parseFile($fullPath);
        if (!is_array($data) || !isset($data['entities']) || !is_array($data['entities'])) {
            $io->error('Invalid YAML. Expected root key "entities".');
            return Command::FAILURE;
        }

        $generated = 0;

        foreach ($data['entities'] as $className => $def) {
            if (!is_string($className) || $className === '') {
                $io->warning('Skipping entity with invalid name.');
                continue;
            }
            if (!is_array($def)) {
                $io->warning(sprintf('Skipping %s: invalid definition', $className));
                continue;
            }

            $namespace = isset($def['namespace']) && is_string($def['namespace'])
                ? $def['namespace']
                : 'App\\Entity';

            $fields = isset($def['fields']) && is_array($def['fields'])
                ? $def['fields']
                : [];

            if ($fields === []) {
                $io->warning(sprintf('Skipping %s: no fields defined', $className));
                continue;
            }

            $code = $this->renderEntity($namespace, $className, $fields);

            $relDir = 'src/' . str_replace('App\\', '', $namespace);
            $relDir = str_replace('\\', '/', $relDir);
            $targetDir = $this->projectDir . '/' . $relDir;

            $this->fs->mkdir($targetDir);

            $targetFile = $targetDir . '/' . $className . '.php';
            if ($this->fs->exists($targetFile)) {
                $io->warning(sprintf('Already exists, skipping: %s', $targetFile));
                continue;
            }

            $this->fs->dumpFile($targetFile, $code);
            $io->success(sprintf('Generated: %s', $targetFile));
            $generated++;

            $controllerDef = isset($def['controller']) && is_array($def['controller']) ? $def['controller'] : [];
            if (($controllerDef['generate'] ?? false) === true) {
                $this->generateControllerForEntity($io, $className, $namespace, $controllerDef);
            }
        }

        $io->note(sprintf('Done. Generated %d file(s).', $generated));
        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        // Absolute on Windows: C:\... or \\server\share, or on Linux: /...
        $isAbsolute = preg_match('#^([A-Za-z]:\\\\|\\\\\\\\|/)#', $path) === 1;
        return $isAbsolute ? $path : ($this->projectDir . '/' . $path);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function renderEntity(string $namespace, string $className, array $fields): string
    {
        $imports = [];
        $props = [];
        $methods = [];

        foreach ($fields as $name => $meta) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (!is_array($meta)) {
                $meta = ['type' => (string) $meta];
            }

            $type = $this->mapType($meta['type'] ?? 'string');
            $default = $meta['default'] ?? null;
            $nullable = (bool) ($meta['nullable'] ?? false);

            $phpType = $nullable && $type !== 'mixed' ? '?' . $type : $type;
            $propDefault = $this->formatDefault($type, $default, $nullable);

            $props[] = sprintf('    private %s $%s%s;', $phpType, $name, $propDefault);

            $ucName = ucfirst($name);

            $getterName = ($type === 'bool' || $type === '?bool') ? 'is' . $ucName : 'get' . $ucName;
            $methods[] =
                "    public function {$getterName}(): {$phpType}\n" .
                "    {\n" .
                "        return \$this->{$name};\n" .
                "    }\n";

            $methods[] =
                "    public function set{$ucName}({$phpType} \${$name}): self\n" .
                "    {\n" .
                "        \$this->{$name} = \${$name};\n" .
                "        return \$this;\n" .
                "    }\n";
        }

        $importsBlock = '';
        if ($imports !== []) {
            $imports = array_values(array_unique($imports));
            sort($imports);
            $importsBlock = "\n" . implode("\n", array_map(fn($i) => "use {$i};", $imports)) . "\n";
        }

        $propsBlock = implode("\n\n", $props);
        $methodsBlock = implode("\n", $methods);

        return <<<PHP
<?php

namespace {$namespace};{$importsBlock}

class {$className}
{
{$propsBlock}

{$methodsBlock}}
PHP;
    }

    private function mapType(mixed $type): string
    {
        $t = is_string($type) ? strtolower(trim($type)) : 'mixed';

        return match ($t) {
            'int', 'integer' => 'int',
            'float', 'double', 'decimal' => 'float',
            'bool', 'boolean' => 'bool',
            'string' => 'string',
            'array' => 'array',
            'datetime', 'datetimeimmutable' => 'mixed', // à étendre si tu veux (DateTimeInterface + import)
            default => 'mixed',
        };
    }

    private function formatDefault(string $type, mixed $default, bool $nullable): string
    {
        if ($default === null) {
            // Si nullable=true et pas de default explicite, on met = null pour éviter "uninitialized"
            return $nullable ? ' = null' : '';
        }

        return match ($type) {
            'int' => ' = ' . (string) (int) $default,
            'float' => ' = ' . (string) (float) $default,
            'bool' => ' = ' . ((bool) $default ? 'true' : 'false'),
            'string' => ' = ' . var_export((string) $default, true),
            'array' => ' = ' . var_export(is_array($default) ? $default : [], true),
            default => ' = ' . var_export($default, true),
        };
    }
private function generateControllerForEntity(SymfonyStyle $io, string $entityShortName, string $entityNamespace, array $controllerDef): void
{
    $controllerNamespace = is_string($controllerDef['namespace'] ?? null) ? $controllerDef['namespace'] : 'App\\Controller';

    $routePrefix = is_string($controllerDef['route_prefix'] ?? null)
        ? rtrim((string) $controllerDef['route_prefix'], '/')
        : '/' . strtolower($entityShortName) . 's';

    $namePrefix = is_string($controllerDef['name_prefix'] ?? null)
        ? (string) $controllerDef['name_prefix']
        : strtolower($entityShortName) . '_';

    $templateDir = is_string($controllerDef['template_dir'] ?? null)
        ? trim((string) $controllerDef['template_dir'], '/')
        : strtolower($entityShortName);

    $controllerShort = $entityShortName . 'Controller';

    $controllerRelDir = 'src/' . str_replace('App\\', '', $controllerNamespace);
    $controllerRelDir = str_replace('\\', '/', $controllerRelDir);
    $controllerDir = $this->projectDir . '/' . $controllerRelDir;

    $this->fs->mkdir($controllerDir);

    $controllerFile = $controllerDir . '/' . $controllerShort . '.php';
    if ($this->fs->exists($controllerFile)) {
        $io->warning(sprintf('Controller exists, skipping: %s', $controllerFile));
        return;
    }

    $entityFqcn = $entityNamespace . '\\' . $entityShortName;

    // IMPORTANT: repository est dans App\Repository (standard Symfony)
    $repoFqcn = 'App\\Repository\\' . $entityShortName . 'Repository';

    $code = $this->renderController(
        $controllerNamespace,
        $controllerShort,
        $entityFqcn,
        $repoFqcn,
        $entityShortName,
        $routePrefix,
        $namePrefix,
        $templateDir
    );

    $this->fs->dumpFile($controllerFile, $code);
    $io->success(sprintf('Generated: %s', $controllerFile));
}

private function renderController(
    string $controllerNamespace,
    string $controllerShort,
    string $entityFqcn,
    string $repoFqcn,
    string $entityShortName,
    string $routePrefix,
    string $namePrefix,
    string $templateDir
): string {
    return <<<PHP
<?php

namespace {$controllerNamespace};

use {$entityFqcn};
use {$repoFqcn};
use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\Routing\\Attribute\\Route;

#[Route('{$routePrefix}', name: '{$namePrefix}')]
class {$controllerShort} extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index({$entityShortName}Repository \$repo): Response
    {
        return \$this->render('{$templateDir}/index.html.twig', [
            'items' => \$repo->findAll(),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\\\d+'], methods: ['GET'])]
    public function show({$entityShortName} \$item): Response
    {
        return \$this->render('{$templateDir}/show.html.twig', [
            'item' => \$item,
        ]);
    }
}

PHP;
}

private function renderIndexTemplate(string $entityShortName, string $namePrefix): string
{
    $title = $entityShortName . ' list';
    return <<<TWIG
{% extends 'base.html.twig' %}

{% block title %}{$title}{% endblock %}

{% block body %}
  <h1>{$title}</h1>

  <ul>
    {% for item in items %}
      <li>
        <a href="{{ path('{$namePrefix}show', {id: item.id}) }}">
          {{ item.id }}
        </a>
      </li>
    {% else %}
      <li>No items</li>
    {% endfor %}
  </ul>
{% endblock %}
TWIG;
}

private function renderShowTemplate(string $entityShortName, string $namePrefix): string
{
    $title = $entityShortName . ' details';
    return <<<TWIG
{% extends 'base.html.twig' %}

{% block title %}{$title}{% endblock %}

{% block body %}
  <h1>{$title}</h1>

  <p><strong>ID:</strong> {{ item.id }}</p>

  <p><a href="{{ path('{$namePrefix}index') }}">Back</a></p>
{% endblock %}
TWIG;
}
}