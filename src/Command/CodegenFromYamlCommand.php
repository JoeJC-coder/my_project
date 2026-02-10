<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:codegen',
    description: 'YAML → Entity + Repository + Controller + Templates + Migration + DB'
)]
class CodegenFromYamlCommand extends Command
{
    public function __construct(
        private readonly Filesystem $fs,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('yaml', InputArgument::OPTIONAL, 'YAML path', 'config/codegen/entities.yaml')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('db', null, InputOption::VALUE_NONE, 'Create database if missing')
            ->addOption('migrate', null, InputOption::VALUE_NONE, 'Generate migration + migrate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectDir = $this->kernel->getProjectDir();
        $yamlPath = (string) $input->getArgument('yaml');
        $yamlFull = $this->resolvePath($projectDir, $yamlPath);

        if (!$this->fs->exists($yamlFull)) {
            $io->error("YAML introuvable: $yamlFull");
            return Command::FAILURE;
        }

        $data = Yaml::parseFile($yamlFull);
        if (!is_array($data) || !isset($data['entities']) || !is_array($data['entities'])) {
            $io->error('YAML invalide: clé racine "entities" attendue.');
            return Command::FAILURE;
        }

        $force = (bool) $input->getOption('force');

        foreach ($data['entities'] as $shortName => $def) {
            if (!is_string($shortName) || !is_array($def)) {
                continue;
            }

            $entityNs = is_string($def['namespace'] ?? null) ? $def['namespace'] : 'App\\Entity';
            $fields = is_array($def['fields'] ?? null) ? $def['fields'] : [];

            if ($fields === []) {
                $io->warning("Skipping $shortName: no fields");
                continue;
            }

            // 1) Entity
            $entityCode = $this->renderEntity($entityNs, $shortName, $fields);
            $entityPath = $this->writePhpClass($projectDir, $entityNs, $shortName, $entityCode, $force, $io);

            // 2) Repository (standard Symfony: App\Repository)
            $repoNs = 'App\\Repository';
            $repoShort = $shortName . 'Repository';
            $repoCode = $this->renderRepository($repoNs, $repoShort, $entityNs . '\\' . $shortName);
            $repoPath = $this->writePhpClass($projectDir, $repoNs, $repoShort, $repoCode, $force, $io);

            // 3) Controller + 4) Templates
            $controllerDef = is_array($def['controller'] ?? null) ? $def['controller'] : [];
            if (($controllerDef['generate'] ?? false) === true) {
                $this->generateControllerAndTemplates($io, $projectDir, $shortName, $entityNs, $controllerDef, $force);
            }

            $io->text("OK: $shortName → $entityPath, $repoPath");
        }

        // 5) Create DB
        if ((bool) $input->getOption('db')) {
            $this->runConsole($io, $projectDir, ['doctrine:database:create', '--if-not-exists']);
        }

        // 6) Migration + migrate
        if ((bool) $input->getOption('migrate')) {
            $this->runConsole($io, $projectDir, ['doctrine:migrations:diff', '--no-interaction']);
            $this->runConsole($io, $projectDir, ['doctrine:migrations:migrate', '--no-interaction']);
        }

        $io->success('Codegen terminé.');
        return Command::SUCCESS;
    }

    private function resolvePath(string $projectDir, string $path): string
    {
        $isAbsolute = preg_match('#^([A-Za-z]:\\\\|\\\\\\\\|/)#', $path) === 1;
        return $isAbsolute ? $path : ($projectDir . '/' . $path);
    }

    private function writePhpClass(
        string $projectDir,
        string $namespace,
        string $classShort,
        string $code,
        bool $force,
        SymfonyStyle $io
    ): string {
        $relDir = 'src/' . str_replace('App\\', '', $namespace);
        $relDir = str_replace('\\', '/', $relDir);
        $dir = $projectDir . '/' . $relDir;
        $this->fs->mkdir($dir);

        $file = $dir . '/' . $classShort . '.php';
        if ($this->fs->exists($file) && !$force) {
            $io->warning("Existe déjà, skip: $file (utilise --force pour écraser)");
            return $file;
        }
        $this->fs->dumpFile($file, $code);
        $io->success("Generated: $file");
        return $file;
    }

    private function generateControllerAndTemplates(
        SymfonyStyle $io,
        string $projectDir,
        string $entityShort,
        string $entityNs,
        array $controllerDef,
        bool $force
    ): void {
        $controllerNs = is_string($controllerDef['namespace'] ?? null) ? $controllerDef['namespace'] : 'App\\Controller';
        $routePrefix = is_string($controllerDef['route_prefix'] ?? null) ? rtrim($controllerDef['route_prefix'], '/') : '/' . strtolower($entityShort) . 's';
        $namePrefix = is_string($controllerDef['name_prefix'] ?? null) ? $controllerDef['name_prefix'] : strtolower($entityShort) . '_';
        $templateDir = is_string($controllerDef['template_dir'] ?? null) ? trim($controllerDef['template_dir'], '/') : strtolower($entityShort);

        $controllerShort = $entityShort . 'Controller';
        $entityFqcn = $entityNs . '\\' . $entityShort;
        $repoFqcn = 'App\\Repository\\' . $entityShort . 'Repository';

        $controllerCode = $this->renderController($controllerNs, $controllerShort, $entityFqcn, $repoFqcn, $entityShort, $routePrefix, $namePrefix, $templateDir);
        $this->writePhpClass($projectDir, $controllerNs, $controllerShort, $controllerCode, $force, $io);

        // Templates
        $tplBase = $projectDir . '/templates/' . $templateDir;
        $this->fs->mkdir($tplBase);

        $index = $tplBase . '/index.html.twig';
        if (!$this->fs->exists($index) || $force) {
            $this->fs->dumpFile($index, $this->renderIndexTemplate($entityShort, $namePrefix));
            $io->success("Generated: $index");
        }

        $show = $tplBase . '/show.html.twig';
        if (!$this->fs->exists($show) || $force) {
            $this->fs->dumpFile($show, $this->renderShowTemplate($entityShort, $namePrefix));
            $io->success("Generated: $show");
        }
    }

    private function runConsole(SymfonyStyle $io, string $projectDir, array $args): void
    {
        $php = PHP_BINARY; // utilise le php courant
        $cmd = array_merge([$php, 'bin/console'], $args);

        $io->section('Run: ' . implode(' ', $args));
        $p = new Process($cmd, $projectDir);
        $p->setTimeout(300);
        $p->run(function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });

        if (!$p->isSuccessful()) {
            throw new \RuntimeException($p->getErrorOutput() ?: 'Console command failed');
        }
    }

    private function renderEntity(string $namespace, string $className, array $fields): string
    {
        // Version simple (POPO). Tu peux ensuite ajouter Doctrine Attributes.
        $props = [];
        $methods = [];

        foreach ($fields as $name => $meta) {
            if (!is_string($name)) continue;
            $meta = is_array($meta) ? $meta : ['type' => (string) $meta];
            $type = $this->mapType($meta['type'] ?? 'string');
            $default = $meta['default'] ?? null;

            $props[] = '    private ' . $type . ' $' . $name . ($default !== null ? ' = ' . var_export($default, true) : '') . ';';

            $uc = ucfirst($name);
            $methods[] = "    public function get{$uc}(): {$type}\n    {\n        return \$this->{$name};\n    }\n";
            $methods[] = "    public function set{$uc}({$type} \${$name}): self\n    {\n        \$this->{$name} = \${$name};\n        return \$this;\n    }\n";
        }

        return <<<PHP
<?php

namespace {$namespace};

class {$className}
{
{$this->joinBlocks($props)}

{$this->joinBlocks($methods)}}

PHP;
    }

    private function renderRepository(string $namespace, string $className, string $entityFqcn): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use {$entityFqcn};
use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;
use Doctrine\\Persistence\\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<{$this->short($entityFqcn)}>
 */
class {$className} extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry \$registry)
    {
        parent::__construct(\$registry, {$this->short($entityFqcn)}::class);
    }
}

PHP;
    }

    private function renderController(
        string $controllerNs,
        string $controllerShort,
        string $entityFqcn,
        string $repoFqcn,
        string $entityShort,
        string $routePrefix,
        string $namePrefix,
        string $templateDir
    ): string {
        return <<<PHP
<?php

namespace {$controllerNs};

use {$entityFqcn};
use {$repoFqcn};
use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\Routing\\Attribute\\Route;

#[Route('{$routePrefix}', name: '{$namePrefix}')]
class {$controllerShort} extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index({$entityShort}Repository \$repo): Response
    {
        return \$this->render('{$templateDir}/index.html.twig', [
            'items' => \$repo->findAll(),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\\\d+'], methods: ['GET'])]
    public function show({$entityShort} \$item): Response
    {
        return \$this->render('{$templateDir}/show.html.twig', [
            'item' => \$item,
        ]);
    }
}

PHP;
    }

    private function renderIndexTemplate(string $entityShort, string $namePrefix): string
    {
        return <<<TWIG
{% extends 'base.html.twig' %}

{% block title %}{$entityShort} list{% endblock %}

{% block body %}
  <h1>{$entityShort} list</h1>

  <ul>
    {% for item in items %}
      <li><a href="{{ path('{$namePrefix}show', {id: item.id}) }}">{{ item.id }}</a></li>
    {% else %}
      <li>No items</li>
    {% endfor %}
  </ul>
{% endblock %}
TWIG;
    }

    private function renderShowTemplate(string $entityShort, string $namePrefix): string
    {
        return <<<TWIG
{% extends 'base.html.twig' %}

{% block title %}{$entityShort} details{% endblock %}

{% block body %}
  <h1>{$entityShort} details</h1>
  <p><strong>ID:</strong> {{ item.id }}</p>
  <p><a href="{{ path('{$namePrefix}index') }}">Back</a></p>
{% endblock %}
TWIG;
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
            default => 'mixed',
        };
    }

    private function joinBlocks(array $blocks): string
    {
        return implode("\n\n", array_map(fn($b) => rtrim($b), $blocks));
    }

    private function short(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }
}
