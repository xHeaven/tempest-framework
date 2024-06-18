<?php

declare(strict_types=1);

namespace Tempest\View;

use Exception;
use PHPHtmlParser\Dom;
use Tempest\Application\AppConfig;
use Tempest\Container\Container;
use Tempest\View\Attributes\AttributeFactory;
use Tempest\View\Components\AnonymousViewComponent;
use Tempest\View\Elements\CollectionElement;
use Tempest\View\Elements\ElementFactory;
use Tempest\View\Elements\EmptyElement;
use Tempest\View\Elements\GenericElement;
use Tempest\View\Elements\SlotElement;
use Tempest\View\Elements\TextElement;
use function Tempest\path;

final readonly class ViewRenderer
{
    public function __construct(
        private ElementFactory $elementFactory,
        private AttributeFactory $attributeFactory,
        private AppConfig $appConfig,
        private ViewConfig $viewConfig,
        private Container $container,
    ) {}

    public function render(?View $view): string
    {
        if ($view === null) {
            return '';
        }

        $contents = $this->resolveContent($view);

        $dom = new Dom();

        $dom->load('<div>' . $contents . '</div>');

        $element = $this->applyAttributes(
            view: $view,
            element: $this->elementFactory->make($view,
                $dom->root->getChildren()[0],
            ),
        );

        return trim($this->renderElements($view, $element->getChildren()));
    }

    /** @param \Tempest\View\Element[] $elements */
    private function renderElements(View $view, array $elements): string
    {
        $rendered = [];

        foreach ($elements as $element) {
            $rendered[] = $this->renderElement($view, $element);
        }

        return implode('', $rendered);
    }

    public function renderElement(View $view, Element $element): string
    {
        if ($element instanceof CollectionElement) {
            return $this->renderCollectionElement($view, $element);
        }

        if ($element instanceof TextElement) {
            return $this->renderTextElement($view, $element);
        }

        if ($element instanceof EmptyElement) {
            return $this->renderEmptyElement($element);
        }

        if ($element instanceof SlotElement) {
            return $this->renderSlotElement($view, $element);
        }

        if ($element instanceof GenericElement) {
            $viewComponent = $this->resolveViewComponent($element);

            if (! $viewComponent) {
                return $this->renderGenericElement($view, $element);
            }

            return $this->renderViewComponent(
                view: $view,
                viewComponent: $viewComponent,
                element: $element,
            );
        }

        // Cannot render
    }

    private function resolveContent(View $view): string
    {
        $path = $view->getPath();

        if (! str_ends_with($path, '.php')) {
            ob_start();

            /** @phpstan-ignore-next-line */
            eval('?>' . $path . '<?php');

            return ob_get_clean();
        }

        $discoveryLocations = $this->appConfig->discoveryLocations;

        while (! file_exists($path) && $location = current($discoveryLocations)) {
            $path = path($location->path, $view->getPath());
            next($discoveryLocations);
        }

        if (! file_exists($path)) {
            throw new Exception("View {$path} not found");
        }

        ob_start();

        include $path;

        return ob_get_clean();
    }

    private function resolveViewComponent(GenericElement $element): ?ViewComponent
    {
        /** @var class-string<\Tempest\View\ViewComponent>|null $component */
        $viewComponentClass = $this->viewConfig->viewComponents[$element->getTag()] ?? null;

        if (! $viewComponentClass) {
            return null;
        }

        if ($viewComponentClass instanceof ViewComponent) {
            return $viewComponentClass;
        } else {
            return $this->container->get($viewComponentClass);
        }
    }

    private function applyAttributes(View $view, Element $element): Element
    {
        if (! $element instanceof GenericElement) {
            return $element;
        }

        $children = [];

        foreach ($element->getChildren() as $child) {
            $children[] = $this->applyAttributes($view, $child);
        }

        $element->setChildren($children);

        foreach ($element->getAttributes() as $name => $value) {
            $attribute = $this->attributeFactory->make($view, $name, $value);

            $element = $attribute->apply($element);
        }

        return $element;
    }

    private function renderTextElement(View $view, TextElement $element): string
    {
        return preg_replace_callback(
            pattern: '/{{\s*(?<eval>\$.*?)\s*}}/',
            callback: function (array $matches) use ($element, $view): string {
                $eval = $matches['eval'] ?? '';

                if (str_starts_with($eval, '$this->')) {
                    return $view->eval($eval) ?? '';
                }

                return $element->getData()[ltrim($eval, '$')] ?? '';
            },
            subject: $element->getText(),
        );
    }

    private function renderCollectionElement(View $view, CollectionElement $collectionElement): string
    {
        $rendered = [];

        foreach ($collectionElement->getElements() as $element) {
            $rendered[] = $this->renderElement($view, $element);
        }

        return implode(PHP_EOL, $rendered);
    }

    private function renderViewComponent(View $view, ViewComponent $viewComponent, GenericElement $element): string
    {
        return preg_replace_callback(
            pattern: '/<x-slot\s*(name="(?<name>\w+)")?\s*\/>/',
            callback: function ($matches) use ($view, $element) {
                $name = $matches['name'] ?? 'slot';

                $slot = $element->getSlot($name);

                if (! $slot) {
                    return $matches[0];
                }

                return $this->renderElement($view, $slot);
            },
            subject: $viewComponent->render($element, $this),
        );
    }

    private function renderEmptyElement(EmptyElement $element): string
    {
        return '';
    }

    private function renderSlotElement(View $view, SlotElement $element): string
    {
        $rendered = [];

        foreach ($element->getChildren() as $child) {
            $rendered[] = $this->renderElement($view, $child);
        }

        return implode(PHP_EOL, $rendered);
    }


    private function renderGenericElement(View $view, GenericElement $element): string
    {
        $content = [];

        foreach ($element->getChildren() as $child) {
            $content[] = $this->renderElement($view, $child);
        }

        $content = implode('', $content);

        $attributes = [];

        foreach ($element->getAttributes() as $name => $value) {
            if ($value) {
                $attributes[] = $name . '="' . $value . '"';
            } else {
                $attributes[] = $name;
            }
        }

        $attributes = implode(' ', $attributes);

        if ($attributes !== '') {
            $attributes = ' ' . $attributes;
        }

        return "<{$element->getTag()}{$attributes}>{$content}</{$element->getTag()}>";
    }
}
