<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Form\Field\DisplayField;
use Kirby\Panel\Ui\Button\ModelButton;
use Kirby\Reflection\Attributes\Prop;
use Kirby\Toolkit\HtmlString;
use Kirby\Toolkit\I18n;
use Kirby\Uuid\Uuids;

final class JanitorField extends DisplayField
{
    #[Prop]
    protected mixed $autosave = false;

    #[Prop]
    protected mixed $backgroundColor = '';

    #[Prop]
    protected ?array $badge = null;

    #[Prop]
    protected array|string|null $buttons = null;

    #[Prop]
    protected ?string $dialog = null;

    #[Prop]
    protected bool $disabled = false;

    #[Prop]
    protected ?string $drawer = null;

    #[Prop]
    protected ?string $link = null;

    #[Prop]
    protected ?string $target = null;

    #[Prop]
    protected array|string|null $text = null;

    #[Prop]
    protected ?string $theme = null;

    #[Prop]
    protected array|string|null $title = null;

    #[Prop]
    protected mixed $clipboard = false;

    #[Prop]
    protected mixed $color = '';

    #[Prop]
    protected string $command = '';

    #[Prop]
    protected array|string|null $confirm = null;

    #[Prop]
    protected mixed $cooldown = null;

    #[Prop]
    protected array|string|null $error = null;

    #[Prop]
    protected array|string|null $headline = null;

    #[Prop]
    protected mixed $icon = '';

    #[Prop]
    protected mixed $intab = false;

    #[Prop]
    protected array|string|null $progress = null;

    #[Prop]
    protected array|string|null $success = null;

    #[Prop]
    protected mixed $unsaved = true;

    public function command(): string
    {
        if ($this->command === '') {
            return '';
        }

        $model = $this->model();
        // Simultaneous replacement: {{ ... }} waits until dispatch, {< ... >} resolves now.
        $command = strtr($this->command, ['{{' => '{(', '}}' => ')}', '{<' => '{{', '>}' => '}}']);
        $command = Janitor::query($command, $model);

        if ($model instanceof Site) {
            return $command.' --site --model site:// --quiet';
        }

        $id = $model->id();
        if ($model instanceof Page || $model instanceof File || $model instanceof User) {
            // Reading the stored UUID must not generate one just by displaying a button.
            $uuid = $model->content('default')->get('uuid')->toString();
            $type = $model::CLASS_ALIAS;
            if ($uuid !== '' && Uuids::enabled()) {
                $id = $type.'://'.$uuid;
            }
            $command .= ' --'.$type.' '.$id;
        }

        return $command.' --model '.$id.' --quiet';
    }

    public function cooldown(): int
    {
        $value = $this->cooldown ?? option('bnomei.janitor.label.cooldown', 2000);

        return is_scalar($value) || is_array($value) ? (int) $value : 2000;
    }

    protected function translatedText(array|string|null $text, ?string $fallback = null): string
    {
        return Janitor::query(I18n::translate($text, $text, $this->kirby()->user()?->language()), $this->model(), $fallback);
    }

    public function help(): ?HtmlString
    {
        $user = $this->kirby()->user();
        $text = I18n::translate($this->help, $this->help, $user?->language());
        if (! is_string($text) || $text === '') {
            return null;
        }

        $model = $this->model();
        $text = $model->toSafeString($text, ['user' => $model instanceof User ? $model : $user]);

        return new HtmlString($this->kirby()->kirbytext($text));
    }

    public function label(): string
    {
        if ($this->buttons !== null) {
            return parent::label();
        }

        return $this->translatedText($this->label, '');
    }

    public function buttons(): ?array
    {
        if ($this->buttons === null) {
            return null;
        }

        $definitions = is_string($this->buttons) ? $this->model()->query($this->buttons) : $this->buttons;
        if (! is_array($definitions) || ! array_is_list($definitions)) {
            throw new \InvalidArgumentException('Janitor buttons must be a list or a query returning a list.');
        }

        $buttons = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition) || isset($definition['buttons'])) {
                throw new \InvalidArgumentException('Each Janitor list entry must be a single button definition.');
            }
            $button = new self(model: $this->model());
            $button->register($definition);
            $buttons[] = $button->props();
        }

        return $buttons;
    }

    public function props(): array
    {
        $actions = array_filter([$this->command, $this->link, $this->dialog, $this->drawer], static fn ($value) => $value !== null && $value !== '');
        if (count($actions) > 1 || ($this->buttons !== null && $actions !== [])) {
            throw new \InvalidArgumentException('Choose one of command, link, dialog, drawer or buttons.');
        }

        $props = parent::props();
        $native = new ModelButton(
            model: $this->model(), text: $this->text, title: $this->title, theme: $this->theme,
            link: $this->link, dialog: $this->dialog, drawer: $this->drawer,
        );
        foreach (['text', 'title', 'theme', 'link', 'dialog', 'drawer'] as $key) {
            $props[$key] = $native->{$key}();
        }
        foreach (['autosave', 'clipboard', 'intab', 'unsaved'] as $key) {
            $props[$key] = Janitor::isTrue($props[$key]);
        }
        foreach (['confirm', 'error', 'headline', 'progress', 'success'] as $key) {
            $props[$key] = $this->translatedText($this->{$key});
        }
        foreach (['backgroundColor', 'color', 'icon'] as $key) {
            $props[$key] = Janitor::query($props[$key], $this->model());
        }

        return $props;
    }
}
