<?php

namespace Blueprint\Generators\Statements;

use Blueprint\Blueprint;
use Blueprint\Contracts\Generator;
use Blueprint\Models\Controller;
use Blueprint\Models\Statements\ValidateStatement;
use Blueprint\Translators\Rules;
use Illuminate\Support\Str;

class FormRequestGenerator implements Generator
{
    private const INDENT = '            ';

    /**
     * @var \Illuminate\Contracts\Filesystem\Filesystem
     */
    private $files;

    private $models = [];

    public function __construct($files)
    {
        $this->files = $files;
    }

    public function output(array $tree): array
    {
        $output = [];

        $stub = $this->files->stub('form-request.stub');

        $this->registerModels($tree);

        /** @var \Blueprint\Models\Controller $controller */
        foreach ($tree['controllers'] as $controller) {
            foreach ($controller->methods() as $method => $statements) {
                foreach ($statements as $statement) {
                    if (! $statement instanceof ValidateStatement) {
                        continue;
                    }

                    $context = Str::singular($controller->prefix());
                    $name = $this->getName($context, $method);
                    $path = $this->getPath($controller, $name);

                    if ($this->files->exists($path)) {
                        continue;
                    }

                    if (! $this->files->exists(dirname($path))) {
                        $this->files->makeDirectory(dirname($path), 0755, true);
                    }

                    $this->files->put($path, $this->populateStub($stub, $name, $context, $statement, $controller));

                    $output['created'][] = $path;
                }
            }
        }

        return $output;
    }

    public function types(): array
    {
        return ['controllers', 'requests'];
    }

    protected function getPath(Controller $controller, string $name)
    {
        return Blueprint::appPath().'/Http/Requests/'.($controller->namespace() ? $controller->namespace().'/' : '').$name.'.php';
    }

    protected function populateStub(string $stub, string $name, $context, ValidateStatement $validateStatement, Controller $controller)
    {
        $stub = str_replace('DummyNamespace', config('blueprint.namespace').'\\Http\\Requests'.($controller->namespace() ? '\\'.$controller->namespace() : ''), $stub);
        $stub = str_replace('DummyClass', $name, $stub);
        $stub = str_replace('// rules...', $this->buildRules($context, $validateStatement), $stub);

        return $stub;
    }

    protected function buildRules(string $context, ValidateStatement $validateStatement)
    {
        return trim(array_reduce($validateStatement->data(), function ($output, $field) use ($context) {
            [$qualifier, $column] = $this->splitField($field);

            if (is_null($qualifier)) {
                $qualifier = $context;
            }

            $validationRules = $this->validationRules($qualifier, $column);

            foreach ($validationRules as $name => $rule) {
                $formattedRule = implode('|', $rule);

                $output .= self::INDENT."'{$name}' => '{$formattedRule}',".PHP_EOL;
            }

            return $output;
        }, ''));
    }

    private function modelForContext(string $context)
    {
        if (isset($this->models[Str::studly($context)])) {
            return $this->models[Str::studly($context)];
        }

        $matches = array_filter(array_keys($this->models), function ($key) use ($context) {
            return Str::endsWith($key, '/'.Str::studly($context));
        });

        if (count($matches) === 1) {
            return $this->models[$matches[0]];
        }
    }

    private function getName(string $context, string $method)
    {
        return $context.Str::studly($method).'Request';
    }

    private function splitField($field)
    {
        if (Str::contains($field, '.')) {
            return explode('.', $field, 2);
        }

        return [null, $field];
    }

    private function validationRules(string $qualifier, string $column)
    {
        /** @var \Blueprint\Models\Model $model */
        $model = $this->modelForContext($qualifier);

        $rules = [];

        if (! is_null($model)) {
            if ($model->hasColumn($column)) {
                $modelColumn = $model->column($column);

                $rules[$column] = Rules::fromColumn($model->tableName(), $modelColumn);

                return $rules;
            } else {
                /** @var \Blueprint\Models\Model $column */
                foreach ($model->columns() as $column) {
                    if ($column->name() === 'id') {
                        continue;
                    }

                    $rules[$column->name()] = Rules::fromColumn($model->tableName(), $column);
                }

                return $rules;
            }
        } else {
            $rules[$column] = ['required'];
        }

        return $rules;
    }

    private function registerModels(array $tree)
    {
        $this->models = array_merge($tree['cache'] ?? [], $tree['models'] ?? []);
    }
}
