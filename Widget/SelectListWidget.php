<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Tui\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\MultiSelectEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\SelectionToggleEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Interactive selection list with keyboard navigation.
 *
 * In single-select mode (default), Enter confirms the highlighted item.
 * In multiselect mode, Space toggles the highlighted item and Enter confirms
 * all checked items.
 *
 * Item `label`, `description`, and `value` are rendered to the terminal
 * as-is and are not sanitized. See {@see TextWidget} for the raw-passthrough
 * contract: never pass untrusted bytes here; sanitize upstream via
 * {@see Util\StringUtils::stripControlBytes()}.
 *
 * @experimental
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class SelectListWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /** @var list<int> */
    private array $filteredItemIndices;

    private int $selectedIndex = 0;
    private bool $selected = false;

    /**
     * @param list<array{value: string, label: string, description?: string, checked?: bool}> $items
     */
    public function __construct(
        private array $items,
        private int $maxVisible = 5,
        private bool $multiselect = false,
        ?Keybindings $keybindings = null,
    ) {
        $this->resetFilteredItems();
        if (null !== $keybindings) {
            $this->setKeybindings($keybindings);
        }
    }

    /**
     * @param list<array{value: string, label: string, description?: string, checked?: bool}> $items
     *
     * @return $this
     */
    public function setItems(array $items): static
    {
        $this->items = $items;
        $this->resetFilteredItems();
        $this->selectedIndex = 0;
        $this->invalidate();

        return $this;
    }

    /**
     * @return $this
     */
    public function setFilter(string $filter): static
    {
        $filter = strtolower($filter);

        $filteredItemIndices = array_keys(array_filter(
            $this->items,
            static fn ($item) => str_starts_with(strtolower($item['value']), $filter),
        ));

        if ($filteredItemIndices !== $this->filteredItemIndices) {
            $this->filteredItemIndices = $filteredItemIndices;
            $this->selectedIndex = 0;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function setSelectedIndex(int $index): static
    {
        $index = max(0, min($index, \count($this->filteredItemIndices) - 1));
        if ($this->selectedIndex !== $index) {
            $this->selectedIndex = $index;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * Get the currently selected item.
     *
     * @return array{value: string, label: string, description?: string, checked?: bool}|null
     */
    public function getSelectedItem(): ?array
    {
        return $this->getFilteredItem($this->selectedIndex);
    }

    /**
     * @return list<array{value: string, label: string, description?: string, checked?: bool}>
     */
    public function getSelectedItems(): array
    {
        if (!$this->multiselect) {
            return [];
        }

        return array_values(
            array_filter($this->items, static fn (array $item) => $item['checked'] ?? false)
        );
    }

    /**
     * Check if an item was selected (Enter pressed) vs cancelled (Escape pressed).
     */
    public function wasSelected(): bool
    {
        return $this->selected;
    }

    /**
     * @param callable(SelectEvent): void $callback
     *
     * @return $this
     */
    public function onSelect(callable $callback): static
    {
        return $this->on(SelectEvent::class, $callback);
    }

    /**
     * @param callable(MultiSelectEvent): void $callback
     *
     * @return $this
     */
    public function onMultiSelect(callable $callback): static
    {
        return $this->on(MultiSelectEvent::class, $callback);
    }

    /**
     * @param callable(SelectionToggleEvent): void $callback
     *
     * @return $this
     */
    public function onSelectionToggle(callable $callback): static
    {
        return $this->on(SelectionToggleEvent::class, $callback);
    }

    /**
     * @param callable(CancelEvent): void $callback
     *
     * @return $this
     */
    public function onCancel(callable $callback): static
    {
        return $this->on(CancelEvent::class, $callback);
    }

    /**
     * @param callable(SelectionChangeEvent): void $callback
     *
     * @return $this
     */
    public function onSelectionChange(callable $callback): static
    {
        return $this->on(SelectionChangeEvent::class, $callback);
    }

    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($this->filteredItemIndices) {
            // Up - wrap to bottom when at top
            if ($kb->matches($data, 'select_up')) {
                $this->selectedIndex = 0 === $this->selectedIndex ? \count($this->filteredItemIndices) - 1 : $this->selectedIndex - 1;
                $this->notifySelectionChange();

                return;
            }

            // Down - wrap to top when at bottom
            if ($kb->matches($data, 'select_down')) {
                $this->selectedIndex = $this->selectedIndex === \count($this->filteredItemIndices) - 1 ? 0 : $this->selectedIndex + 1;
                $this->notifySelectionChange();

                return;
            }

            if ($kb->matches($data, 'select_page_up') || $kb->matches($data, 'cursor_left')) {
                $this->selectedIndex = max(0, $this->selectedIndex - $this->maxVisible);
                $this->notifySelectionChange();

                return;
            }

            if ($kb->matches($data, 'select_page_down') || $kb->matches($data, 'cursor_right')) {
                $this->selectedIndex = min(\count($this->filteredItemIndices) - 1, $this->selectedIndex + $this->maxVisible);
                $this->notifySelectionChange();

                return;
            }

            if ($this->multiselect && $kb->matches($data, 'choice_toggle')) {
                $this->toggleCurrentItem();

                return;
            }

            // Confirm selection
            if ($kb->matches($data, 'select_confirm')) {
                $this->confirmSelection();

                return;
            }
        }

        // Cancel
        if ($kb->matches($data, 'select_cancel')) {
            $this->selected = false;
            $this->dispatch(new CancelEvent($this));
        }
    }

    /**
     * @return string[]
     */
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $lines = [];

        // No items match filter
        if (!$this->filteredItemIndices) {
            $line = $this->applyElement('no-match', '  No matching items');
            $lines[] = AnsiUtils::truncateToWidth($line, $columns, '');

            return $lines;
        }

        // Calculate visible range with scrolling
        $startIndex = max(
            0,
            min(
                $this->selectedIndex - (int) floor($this->maxVisible / 2),
                \count($this->filteredItemIndices) - $this->maxVisible,
            ),
        );
        $endIndex = min($startIndex + $this->maxVisible, \count($this->filteredItemIndices));

        // Compute max label width from visible items for alignment
        $maxLabelWidth = 0;
        for ($i = $startIndex; $i < $endIndex; ++$i) {
            $maxLabelWidth = max($maxLabelWidth, AnsiUtils::visibleWidth($this->getFilteredItem($i)['label']));
        }
        $labelColumnWidth = min(30, $maxLabelWidth);

        // Render visible items
        // Items may wrap across several physical rows, so fit the logical
        // window against the context rows before emitting anything.
        [$lines, $startIndex, $endIndex, $indicatorRoom] = $this->renderWindow($startIndex, $endIndex, $context->getRows(), $columns, $labelColumnWidth);

        // Add scroll indicator if needed
        if ($indicatorRoom && ($startIndex > 0 || $endIndex < \count($this->filteredItemIndices))) {
            $scrollText = \sprintf('  (%d/%d)', $this->selectedIndex + 1, \count($this->filteredItemIndices));
            $line = $this->applyElement('scroll-info', AnsiUtils::truncateToWidth($scrollText, $columns - 2, ''));
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, 'ctrl+c'],
            'cursor_left' => [Key::LEFT, 'ctrl+b'],
            'cursor_right' => [Key::RIGHT, 'ctrl+f'],
            'choice_toggle' => [Key::SPACE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getKeybindingLabels(): array
    {
        if (null !== $this->keybindingLabels) {
            return $this->keybindingLabels;
        }

        $labels = static::getDefaultKeybindingLabels();

        if (!$this->multiselect) {
            unset($labels['choice_toggle']);
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    protected static function getDefaultKeybindingLabels(): array
    {
        return [
            'select_down' => 'Down',
            'select_up' => 'Up',
            'select_page_down' => 'Page Down',
            'select_page_up' => 'Page Up',
            'choice_toggle' => 'Toggle',
            'select_confirm' => 'Select',
            'select_cancel' => 'Cancel',
        ];
    }

    /**
     * Render the logical window as physical rows fitted to the context.
     *
     * Wrapped items consume a variable number of rows, so trailing items
     * are dropped and leading items are trimmed until the selected item
     * fits inside the budget; a selected item taller than the budget is
     * clamped to its first rows. One row is reserved for the scroll
     * indicator only when items actually fall outside the fitted window,
     * so a window that fits exactly renders without an indicator.
     *
     * A one-row viewport has no room next to the selection, so the
     * indicator is suppressed and its row goes to the selected label.
     *
     * @return array{0: list<string>, 1: int, 2: int, 3: bool} the rendered rows, the fitted [start, end) range and whether a row is left for the indicator
     */
    private function renderWindow(int $startIndex, int $endIndex, int $contextRows, int $columns, int $labelColumnWidth): array
    {
        $rowsByIndex = [];
        for ($i = $startIndex; $i < $endIndex; ++$i) {
            $item = $this->getFilteredItem($i);
            $description = isset($item['description']) ? $this->normalizeDescription($item['description']) : null;
            $rowsByIndex[$i] = $this->renderItemRows($item, $i === $this->selectedIndex, $description, $columns, $labelColumnWidth);
        }

        $total = \count($this->filteredItemIndices);

        $fit = function (int $budget) use ($rowsByIndex, $startIndex, $endIndex): array {
            // Keep every item through the selection first, then drop trailing
            // items once the budget is spent.
            $end = $endIndex;
            $used = 0;
            foreach ($rowsByIndex as $i => $rows) {
                if ($i > $this->selectedIndex && $used + \count($rows) > $budget) {
                    $end = $i;
                    break;
                }
                $used += \count($rows);
            }

            // Trim leading items while they push the selection out of view.
            $start = $startIndex;
            while ($start < $this->selectedIndex && $used > $budget) {
                $used -= \count($rowsByIndex[$start]);
                ++$start;
            }

            // After reclaiming rows from leading items, try trailing items
            // that were dropped before that trim.
            while ($end < $endIndex && $used + \count($rowsByIndex[$end]) <= $budget) {
                $used += \count($rowsByIndex[$end]);
                ++$end;
            }

            // The selected item alone exceeds the budget: clamp it to its
            // first rows so the arrow and the label start stay visible.
            $clamp = max(0, $used - $budget);

            return [$start, $end, $clamp];
        };

        [$start, $end, $clamp] = $fit(max(1, $contextRows));
        $indicatorRoom = $contextRows >= 2;
        if ($indicatorRoom && ($start > 0 || $end < $total)) {
            // Something scrolled out, so one row goes to the indicator.
            [$start, $end, $clamp] = $fit(max(1, $contextRows - 1));
        }

        $lines = [];
        for ($i = $start; $i < $end; ++$i) {
            $rows = $rowsByIndex[$i];
            if ($i === $start && $clamp > 0) {
                $rows = \array_slice($rows, 0, \count($rows) - $clamp);
            }
            // The prefix is wider than very narrow widgets, so clamp every
            // physical row to the pane width.
            foreach ($rows as $row) {
                $lines[] = AnsiUtils::truncateToWidth($row, $columns, '');
            }
        }

        return [$lines, $start, $end, $indicatorRoom];
    }

    /**
     * Render one item with label and description wrapped independently in
     * their side-by-side columns. Continuation rows keep the arrow only on
     * the first physical row and align under each column.
     *
     * @param array{value: string, label: string, description?: string, checked?: bool} $item
     *
     * @return list<string>
     */
    private function renderItemRows(array $item, bool $isSelected, ?string $description, int $columns, int $labelColumnWidth): array
    {
        $checkbox = $this->multiselect ? (($item['checked'] ?? false) ? '[x] ' : '[ ] ') : '';
        $prefix = $isSelected ? '→ '.$checkbox : '  '.$checkbox;
        $prefixWidth = AnsiUtils::visibleWidth($prefix);
        $selectedStyle = $isSelected ? $this->resolveElement('selected') : null;

        // Keep the existing side-by-side layout when there is room for a
        // description column; otherwise fall back to a full-width label.
        $showDescription = null !== $description && $columns > 40;
        $labelWidth = max(1, min($labelColumnWidth, $columns - $prefixWidth - 4));
        $alignedWidth = $labelWidth + 2;
        $descriptionStart = $prefixWidth + $alignedWidth;
        $descriptionWidth = $columns - $descriptionStart - 2;

        if (!$showDescription || $descriptionWidth <= 10) {
            $labelWidth = max(1, $columns - $prefixWidth - 2);
            $labelRows = TextWrapper::wrapTextWithAnsi($item['label'], $labelWidth);
            $rows = [];
            foreach ($labelRows as $i => $labelRow) {
                $row = (0 === $i ? $prefix : str_repeat(' ', $prefixWidth)).$labelRow;
                $rows[] = null !== $selectedStyle ? $selectedStyle->apply($row) : $row;
            }

            return $rows;
        }

        $labelRows = TextWrapper::wrapTextWithAnsi($item['label'], $labelWidth);
        $descriptionRows = TextWrapper::wrapTextWithAnsi($description, max(1, $descriptionWidth));
        $rowCount = max(\count($labelRows), \count($descriptionRows));
        $rows = [];

        for ($i = 0; $i < $rowCount; ++$i) {
            $labelRow = $labelRows[$i] ?? '';
            $descriptionRow = $descriptionRows[$i] ?? '';
            $rowPrefix = 0 === $i ? $prefix : str_repeat(' ', $prefixWidth);

            if ('' === $descriptionRow) {
                $row = $rowPrefix.$labelRow;
                $rows[] = null !== $selectedStyle ? $selectedStyle->apply($row) : ('' !== $labelRow ? $rowPrefix.$this->applyElement('label', $labelRow) : $rowPrefix);

                continue;
            }

            // Pad to the shared label column so descriptions stay aligned
            // across items, matching the previous single-row layout.
            $labelPadding = str_repeat(' ', max(1, $alignedWidth - AnsiUtils::visibleWidth($labelRow)));

            if (null !== $selectedStyle) {
                $rows[] = $selectedStyle->apply($rowPrefix.$labelRow.$labelPadding.$descriptionRow);

                continue;
            }

            $labelText = '' !== $labelRow ? $this->applyElement('label', $labelRow) : '';
            // Keep the inter-column gap outside the description style so the
            // gray color does not paint the alignment spaces.
            $rows[] = $rowPrefix.$labelText.$labelPadding.$this->applyElement('description', $descriptionRow);
        }

        return $rows;
    }

    private function resetFilteredItems(): void
    {
        $this->filteredItemIndices = array_keys($this->items);
    }

    private function normalizeDescription(string $description): string
    {
        // Convert multiline to single line
        return trim(preg_replace('/[\r\n]+/', ' ', $description));
    }

    private function toggleCurrentItem(): void
    {
        if (!$this->multiselect) {
            return;
        }

        if (null === $itemIndex = $this->filteredItemIndices[$this->selectedIndex] ?? null) {
            return;
        }

        $this->items[$itemIndex]['checked'] = $checked = !($this->items[$itemIndex]['checked'] ?? false);

        $this->invalidate();
        $this->dispatch(new SelectionToggleEvent($this, $this->items[$itemIndex], $checked, $this->getSelectedItems()));
    }

    private function confirmSelection(): void
    {
        $this->selected = true;

        if ($this->multiselect) {
            $this->dispatch(new MultiSelectEvent($this, $this->getSelectedItems()));

            return;
        }

        if (null !== $selectedItem = $this->getFilteredItem($this->selectedIndex)) {
            $this->dispatch(new SelectEvent($this, $selectedItem));
        }
    }

    private function notifySelectionChange(): void
    {
        $this->invalidate();
        if (null !== $selectedItem = $this->getFilteredItem($this->selectedIndex)) {
            $this->dispatch(new SelectionChangeEvent($this, $selectedItem));
        }
    }

    /**
     * @return array{value: string, label: string, description?: string, checked?: bool}|null
     */
    private function getFilteredItem(int $index): ?array
    {
        return isset($this->filteredItemIndices[$index]) ? $this->items[$this->filteredItemIndices[$index]] : null;
    }
}
