<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Tui\Tests\Widget;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\MultiSelectEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\SelectionToggleEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;

class SelectListTest extends TestCase
{
    public function testRenderShowsItems()
    {
        $list = $this->createTestList();
        $lines = $list->render(new RenderContext(80, 24));

        $this->assertStringContainsString('Option 1', $lines[0]);
    }

    public function testRenderShowsSelectedIndicator()
    {
        $list = $this->createTestList();
        $lines = $list->render(new RenderContext(80, 24));

        // First item should have arrow indicator
        $this->assertStringContainsString('→', $lines[0]);
    }

    public function testNavigateDown()
    {
        $list = $this->createTestList();

        $this->assertSame('opt1', $list->getSelectedItem()['value']);

        // Simulate down arrow
        $list->handleInput("\x1b[B");

        $this->assertSame('opt2', $list->getSelectedItem()['value']);
    }

    public function testNavigateUp()
    {
        $list = $this->createTestList();
        $list->setSelectedIndex(2);

        $this->assertSame('opt3', $list->getSelectedItem()['value']);

        // Simulate up arrow
        $list->handleInput("\x1b[A");

        $this->assertSame('opt2', $list->getSelectedItem()['value']);
    }

    public function testNavigateWrapsAtBottom()
    {
        $list = $this->createTestList();
        $list->setSelectedIndex(2);

        // Simulate down arrow at bottom
        $list->handleInput("\x1b[B");

        $this->assertSame('opt1', $list->getSelectedItem()['value']);
    }

    public function testNavigateWrapsAtTop()
    {
        $list = $this->createTestList();
        $list->setSelectedIndex(0);

        // Simulate up arrow at top
        $list->handleInput("\x1b[A");

        $this->assertSame('opt3', $list->getSelectedItem()['value']);
    }

    public function testOnSelectCallback()
    {
        [$list, $tui] = $this->createTestListWithTui();

        $selectedItem = null;
        $tui->addListener(static function (SelectEvent $e) use (&$selectedItem) {
            $selectedItem = $e->getItem();
        });

        // Simulate Enter
        $list->handleInput("\r");

        $this->assertSame('opt1', $selectedItem['value']);
    }

    public function testOnCancelCallback()
    {
        [$list, $tui] = $this->createTestListWithTui();

        $cancelled = false;
        $tui->addListener(static function (CancelEvent $e) use (&$cancelled) {
            $cancelled = true;
        });

        // Simulate Escape
        $list->handleInput("\x1b");

        $this->assertTrue($cancelled);
    }

    public function testFilter()
    {
        $list = $this->createTestList();

        $list->setFilter('opt2');

        $selected = $list->getSelectedItem();
        $this->assertSame('opt2', $selected['value']);
    }

    public function testFilterNoMatch()
    {
        $list = $this->createTestList();

        $list->setFilter('nonexistent');

        $lines = $list->render(new RenderContext(80, 24));
        $this->assertStringContainsString('No matching', $lines[0]);
    }

    public function testNormalizesMultilineDescription()
    {
        $items = [
            [
                'value' => 'test',
                'label' => 'Test',
                'description' => "Line one\nLine two\nLine three",
            ],
        ];

        $list = new SelectListWidget($items, 5);
        $lines = $list->render(new RenderContext(100, 24));

        $this->assertStringNotContainsString("\n", $lines[0]);
        $this->assertStringContainsString('Line one Line two Line three', $lines[0]);
    }

    public function testRendersWithinWidth()
    {
        $list = $this->createTestList();
        $width = 60;
        $lines = $list->render(new RenderContext($width, 24));

        foreach ($lines as $i => $line) {
            $lineWidth = AnsiUtils::visibleWidth($line);
            $this->assertLessThanOrEqual(
                $width,
                $lineWidth,
                \sprintf('Line %d exceeds width: %d > %d', $i, $lineWidth, $width),
            );
        }
    }

    public function testOnSelectionChangeCallback()
    {
        [$list, $tui] = $this->createTestListWithTui();

        $changedItem = null;
        $tui->addListener(static function (SelectionChangeEvent $e) use (&$changedItem) {
            $changedItem = $e->getItem();
        });

        // Navigate down
        $list->handleInput("\x1b[B");

        $this->assertSame('opt2', $changedItem['value']);
    }

    #[DataProvider('provideNavigationKeysOnEmptyFilteredItems')]
    public function testNavigationOnEmptyFilteredItemsDoesNotCrash(string $input)
    {
        [$list, $tui] = $this->createTestListWithTui();
        $list->setFilter('nonexistent');

        $selectionChanged = false;
        $tui->addListener(static function (SelectionChangeEvent $e) use (&$selectionChanged) {
            $selectionChanged = true;
        });

        $list->handleInput($input);

        $this->assertNull($list->getSelectedItem());
        $this->assertFalse($selectionChanged);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNavigationKeysOnEmptyFilteredItems(): iterable
    {
        yield 'up arrow' => ["\x1b[A"];
        yield 'down arrow' => ["\x1b[B"];
        yield 'page up' => ["\x1b[5~"];
        yield 'page down' => ["\x1b[6~"];
        yield 'confirm' => ["\r"];
    }

    public function testCancelStillWorksOnEmptyFilteredItems()
    {
        [$list, $tui] = $this->createTestListWithTui();
        $list->setFilter('nonexistent');

        $cancelled = false;
        $tui->addListener(static function (CancelEvent $e) use (&$cancelled) {
            $cancelled = true;
        });

        $list->handleInput("\x1b");

        $this->assertTrue($cancelled);
    }

    public function testMultiselectRendersInitialCheckedItems()
    {
        $list = new SelectListWidget([
            ['value' => 'opt1', 'label' => 'Option 1', 'description' => 'First option'],
            ['value' => 'opt2', 'label' => 'Option 2', 'description' => 'Second option', 'checked' => true],
        ], multiselect: true);

        $lines = $list->render(new RenderContext(80, 24));

        $this->assertStringContainsString('[ ] Option 1', $lines[0]);
        $this->assertStringContainsString('[x] Option 2', $lines[1]);
        $this->assertSame([
            ['value' => 'opt2', 'label' => 'Option 2', 'description' => 'Second option', 'checked' => true],
        ], $list->getSelectedItems());
    }

    public function testMultiselectTogglesCurrentItem()
    {
        $list = $this->createTestList(multiselect: true);

        $list->handleInput(' ');

        $this->assertSame([
            ['value' => 'opt1', 'label' => 'Option 1', 'description' => 'First option', 'checked' => true],
        ], $list->getSelectedItems());
        $this->assertStringContainsString('[x] Option 1', $list->render(new RenderContext(80, 24))[0]);

        $list->handleInput(' ');

        $this->assertSame([], $list->getSelectedItems());
        $this->assertStringContainsString('[ ] Option 1', $list->render(new RenderContext(80, 24))[0]);
    }

    public function testSingleSelectIgnoresSpaceToggle()
    {
        $list = $this->createTestList();

        $list->handleInput(' ');
        $lines = $list->render(new RenderContext(80, 24));

        $this->assertSame([], $list->getSelectedItems());
        $this->assertStringNotContainsString('[ ]', $lines[0]);
        $this->assertStringNotContainsString('[x]', $lines[0]);
    }

    public function testMultiselectPreservesTogglesAcrossFilter()
    {
        $list = $this->createTestList(multiselect: true);

        $list->setFilter('opt2');
        $list->handleInput(' ');
        $list->setFilter('');

        $this->assertSame([
            ['value' => 'opt2', 'label' => 'Option 2', 'description' => 'Second option', 'checked' => true],
        ], $list->getSelectedItems());

        $lines = $list->render(new RenderContext(80, 24));
        $this->assertStringContainsString('[ ] Option 1', $lines[0]);
        $this->assertStringContainsString('[x] Option 2', $lines[1]);
    }

    public function testOnSelectionToggleCallback()
    {
        [$list, $tui] = $this->createTestListWithTui(multiselect: true);

        $toggle = null;
        $tui->addListener(static function (SelectionToggleEvent $e) use (&$toggle) {
            $toggle = [
                'value' => $e->getValue(),
                'checked' => $e->isChecked(),
                'selectedItems' => $e->getSelectedItems(),
                'item' => $e->getItem(),
            ];
        });

        $list->handleInput(' ');

        $this->assertSame([
            'value' => 'opt1',
            'checked' => true,
            'selectedItems' => [
                ['value' => 'opt1', 'label' => 'Option 1', 'description' => 'First option', 'checked' => true],
            ],
            'item' => ['value' => 'opt1', 'label' => 'Option 1', 'description' => 'First option', 'checked' => true],
        ], $toggle);
    }

    public function testOnMultiSelectCallback()
    {
        [$list, $tui] = $this->createTestListWithTui(multiselect: true);

        $event = null;
        $tui->addListener(static function (MultiSelectEvent $e) use (&$event) {
            $event = $e;
        });

        $list->handleInput(' ');
        $list->handleInput("\r");

        $this->assertInstanceOf(MultiSelectEvent::class, $event);
        $this->assertFalse($event->isEmpty());
        $this->assertSame(['opt1'], $event->getValues());
        $this->assertSame([
            ['value' => 'opt1', 'label' => 'Option 1', 'description' => 'First option', 'checked' => true],
        ], $event->getItems());
    }

    public function testMultiSelectEventDispatchesWhenEmpty()
    {
        [$list, $tui] = $this->createTestListWithTui(multiselect: true);

        $event = null;
        $tui->addListener(static function (MultiSelectEvent $e) use (&$event) {
            $event = $e;
        });

        $list->handleInput("\r");

        $this->assertInstanceOf(MultiSelectEvent::class, $event);
        $this->assertTrue($event->isEmpty());
        $this->assertSame([], $event->getValues());
        $this->assertSame([], $event->getItems());
    }

    public function testMultiselectRendersWithinWidth()
    {
        $list = $this->createTestList(multiselect: true);
        $width = 60;
        $lines = $list->render(new RenderContext($width, 24));

        foreach ($lines as $i => $line) {
            $lineWidth = AnsiUtils::visibleWidth($line);
            $this->assertLessThanOrEqual(
                $width,
                $lineWidth,
                \sprintf('Line %d exceeds width: %d > %d', $i, $lineWidth, $width),
            );
        }
    }

    private function createTestList(bool $multiselect = false): SelectListWidget
    {
        $items = [
            ['value' => 'opt1', 'label' => 'Option 1', 'description' => 'First option'],
            ['value' => 'opt2', 'label' => 'Option 2', 'description' => 'Second option'],
            ['value' => 'opt3', 'label' => 'Option 3', 'description' => 'Third option'],
        ];

        return new SelectListWidget($items, 5, multiselect: $multiselect);
    }

    public function testNoMatchLineIsTruncatedToTheWidth()
    {
        $list = new SelectListWidget([['value' => 'alpha', 'label' => 'alpha']]);
        $list->setFilter('zzz');

        foreach ([40, 19, 10, 5, 1] as $columns) {
            $lines = $list->render(new RenderContext($columns, 24));

            $this->assertLessThanOrEqual($columns, AnsiUtils::visibleWidth($lines[0]), \sprintf('The no-match line fits in %d columns.', $columns));
        }
    }

    public function testItemsAreTruncatedToTheWidth()
    {
        $list = new SelectListWidget([
            ['value' => 'a', 'label' => 'alpha', 'description' => 'the first one'],
            ['value' => 'b', 'label' => 'beta', 'description' => 'the second one'],
        ]);

        foreach ([60, 40, 20, 6, 4, 2, 1] as $columns) {
            foreach ($list->render(new RenderContext($columns, 24)) as $line) {
                $this->assertLessThanOrEqual($columns, AnsiUtils::visibleWidth($line), \sprintf('Every row fits in %d columns.', $columns));
            }
        }
    }

    public function testCompactItemsStayOnOneRowWhenColumnsFit()
    {
        $list = new SelectListWidget([
            ['value' => 'd', 'label' => 'Development', 'description' => 'Local development server'],
            ['value' => 'p', 'label' => 'Production', 'description' => 'Public production server'],
            ['value' => 'n', 'label' => 'Normal', 'description' => 'Normal'],
        ]);

        $lines = $list->render(new RenderContext(60, 10));

        $this->assertCount(3, $lines);
        $this->assertSame('→ Development  Local development server', AnsiUtils::stripAnsiCodes($lines[0]));
        $this->assertSame('  Production   Public production server', AnsiUtils::stripAnsiCodes($lines[1]));
        $this->assertSame('  Normal       Normal', AnsiUtils::stripAnsiCodes($lines[2]));
        $this->assertSame(
            [
                (new Style())->withBold()->apply('→ Development  Local development server'),
                '  Production   '.(new Style())->withColor('gray')->apply('Public production server'),
                '  Normal       '.(new Style())->withColor('gray')->apply('Normal'),
            ],
            $lines,
        );
    }

    public function testLongLabelWrapsWithinLabelColumnBesideDescription()
    {
        $list = new SelectListWidget([
            ['value' => 'a', 'label' => 'alpha beta gamma delta epsilon zeta', 'description' => 'Public production server'],
            ['value' => 'n', 'label' => 'Normal option', 'description' => 'Normal'],
        ]);

        $lines = $list->render(new RenderContext(80, 10));

        $this->assertSame('→ alpha beta gamma delta epsilon  Public production server', AnsiUtils::stripAnsiCodes($lines[0]));
        $this->assertSame('  zeta', AnsiUtils::stripAnsiCodes($lines[1]));
        $this->assertSame('  Normal option                   Normal', AnsiUtils::stripAnsiCodes($lines[2]));
        $this->assertSame((new Style())->withBold()->apply('→ alpha beta gamma delta epsilon  Public production server'), $lines[0]);
        $this->assertSame((new Style())->withBold()->apply('  zeta'), $lines[1]);
    }

    public function testLongDescriptionWrapsUnderDescriptionColumn()
    {
        $list = new SelectListWidget([
            ['value' => 'd', 'label' => 'Development', 'description' => 'Local development server is long'],
            ['value' => 'p', 'label' => 'Production is long', 'description' => 'Public production server'],
            ['value' => 'n', 'label' => 'Normal', 'description' => 'Normal'],
        ]);

        $lines = $list->render(new RenderContext(48, 10));

        $this->assertSame('→ Development         Local development server', AnsiUtils::stripAnsiCodes($lines[0]));
        $this->assertSame('                      is long', AnsiUtils::stripAnsiCodes($lines[1]));
        $this->assertSame('  Production is long  Public production server', AnsiUtils::stripAnsiCodes($lines[2]));
        $this->assertSame('  Normal              Normal', AnsiUtils::stripAnsiCodes($lines[3]));
        $this->assertStringNotContainsString('(1/', AnsiUtils::stripAnsiCodes(implode("\n", $lines)));
    }

    public function testLabelOnlyListWrapsAcrossAvailableWidth()
    {
        $list = new SelectListWidget([
            ['value' => 'v', 'label' => 'alpha beta gamma delta epsilon zeta'],
        ]);

        $lines = $list->render(new RenderContext(30, 10));

        $this->assertCount(2, $lines);
        $this->assertSame('→ alpha beta gamma delta', AnsiUtils::stripAnsiCodes($lines[0]));
        $this->assertSame('  epsilon zeta', AnsiUtils::stripAnsiCodes($lines[1]));
        $this->assertSame(
            [
                (new Style())->withBold()->apply('→ alpha beta gamma delta'),
                (new Style())->withBold()->apply('  epsilon zeta'),
            ],
            $lines,
        );
    }

    public function testWrappedItemsStayAdjacentWithoutBlankRows()
    {
        $list = new SelectListWidget([
            ['value' => 'd', 'label' => 'Development', 'description' => 'Local development server is long'],
            ['value' => 'p', 'label' => 'Production is long', 'description' => 'Public production server'],
            ['value' => 'n', 'label' => 'Normal', 'description' => 'Normal'],
        ]);

        $plain = array_map(AnsiUtils::stripAnsiCodes(...), $list->render(new RenderContext(48, 12)));

        $this->assertSame('→ Development         Local development server', $plain[0]);
        $this->assertSame('                      is long', $plain[1]);
        $this->assertSame('  Production is long  Public production server', $plain[2]);
        $this->assertSame('  Normal              Normal', $plain[3]);
        $this->assertCount(4, $plain);
    }

    public function testMultiselectWrapsWithAlignedContinuationPrefix()
    {
        $list = new SelectListWidget([
            ['value' => 'a', 'label' => 'alpha beta gamma delta epsilon zeta', 'description' => 'Public production server', 'checked' => true],
            ['value' => 'n', 'label' => 'Normal option', 'description' => 'Normal'],
        ], multiselect: true);

        $lines = $list->render(new RenderContext(80, 10));
        $plain = array_map(AnsiUtils::stripAnsiCodes(...), $lines);

        $this->assertSame('→ [x] alpha beta gamma delta epsilon  Public production server', $plain[0]);
        $this->assertSame('      zeta', $plain[1]);
        $this->assertSame('  [ ] Normal option                   Normal', $plain[2]);
        $this->assertSame((new Style())->withBold()->apply('→ [x] alpha beta gamma delta epsilon  Public production server'), $lines[0]);
        $this->assertSame((new Style())->withBold()->apply('      zeta'), $lines[1]);
    }

    public function testWrappedWindowReclaimsTrailingItemsAfterLeadingTrim()
    {
        $list = new SelectListWidget([
            ['value' => 'a', 'label' => str_repeat('a', 80)],
            ['value' => 'b', 'label' => 'sel'],
            ['value' => 'c', 'label' => 'tail'],
        ], 3);
        $list->setSelectedIndex(1);

        $plain = array_map(AnsiUtils::stripAnsiCodes(...), $list->render(new RenderContext(30, 5)));

        $this->assertLessThanOrEqual(5, \count($plain));
        $this->assertSame('→ sel', $plain[0]);
        $this->assertStringContainsString('tail', $plain[1], 'Trailing items must re-enter the window after leading items are trimmed.');
        $this->assertStringContainsString('(2/3)', implode("\n", $plain));
    }

    public function testWrappedWindowDropsItemsPastTheSelectedOneWhenRowsRunOut()
    {
        $items = [];
        for ($i = 1; $i <= 5; ++$i) {
            $items[] = ['value' => 'v'.$i, 'label' => \sprintf('Item %d ', $i).str_repeat('x', 40)];
        }
        $list = new SelectListWidget($items, 5);

        $lines = $list->render(new RenderContext(30, 4));

        // Three wrapped rows for the selected item plus the scroll indicator.
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('Item 1', AnsiUtils::stripAnsiCodes($lines[0]));
        $this->assertStringContainsString('(1/5)', AnsiUtils::stripAnsiCodes($lines[3]));
    }

    public function testWrappedWindowKeepsSelectedItemInsideAvailableRows()
    {
        $items = [];
        for ($i = 1; $i <= 5; ++$i) {
            $items[] = ['value' => 'v'.$i, 'label' => \sprintf('Item %d ', $i).str_repeat('word ', 17)];
        }
        $list = new SelectListWidget($items, 5);
        $list->setSelectedIndex(2);

        $lines = $list->render(new RenderContext(30, 4));

        $this->assertLessThanOrEqual(4, \count($lines), 'Wrapped rendering must not emit more rows than the context provides.');
        $visible = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
        $this->assertStringContainsString('→ Item 3', $visible, 'The selected item must stay visible inside the available rows.');
    }

    public function testWrappedWindowClampsSelectedItemTallerThanViewport()
    {
        $list = new SelectListWidget([
            ['value' => 'a', 'label' => str_repeat('word ', 20)],
        ]);

        $lines = $list->render(new RenderContext(30, 3));

        $this->assertLessThanOrEqual(3, \count($lines), 'A selected item taller than the viewport must be clamped to the available rows.');
        $this->assertStringContainsString('→ word', AnsiUtils::stripAnsiCodes($lines[0]), 'The first row of the clamped selected item stays anchored at the top.');
    }

    public function testExactFitDoesNotShowScrollIndicator()
    {
        $list = new SelectListWidget([
            ['value' => 'a', 'label' => 'alpha'],
            ['value' => 'b', 'label' => 'beta'],
        ]);

        $lines = $list->render(new RenderContext(20, 2));

        $this->assertCount(2, $lines, 'Both items fit the two available rows exactly; nothing is left to scroll.');
        $this->assertStringContainsString('alpha', AnsiUtils::stripAnsiCodes($lines[0]));
        $this->assertStringContainsString('beta', AnsiUtils::stripAnsiCodes($lines[1]));
    }

    public function testOneRowViewportShowsOnlyTheSelectedLabel()
    {
        $list = new SelectListWidget([
            ['value' => 'a', 'label' => 'alpha'],
            ['value' => 'b', 'label' => 'beta'],
        ]);

        $lines = $list->render(new RenderContext(20, 1));

        $this->assertCount(1, $lines, 'A one-row viewport renders exactly one row.');
        $this->assertStringContainsString('→ alpha', AnsiUtils::stripAnsiCodes($lines[0]), 'The selected label keeps the row; the indicator is suppressed.');
    }

    public function testWrappedRowsFitEveryWidth()
    {
        $list = new SelectListWidget([
            ['value' => 'a', 'label' => 'alpha beta gamma ★ étoile verified', 'description' => 'the first one'],
            ['value' => 'b', 'label' => '日本語のオプション with CJK and ascii mixed', 'description' => 'the second one'],
        ]);

        foreach ([60, 40, 20, 6, 4, 2, 1] as $columns) {
            foreach ($list->render(new RenderContext($columns, 24)) as $line) {
                $this->assertLessThanOrEqual($columns, AnsiUtils::visibleWidth($line), \sprintf('Every wrapped row fits in %d columns.', $columns));
            }
        }
    }

    /**
     * The selected row is prefixed with an arrow: three bytes, two columns.
     * Budgeting in bytes would cost that row two columns of description.
     */
    public function testSelectedRowGetsTheSameWidthBudgetAsTheOthers()
    {
        $description = str_repeat('D', 120);
        $list = new SelectListWidget([
            ['value' => 'alpha', 'label' => 'alpha', 'description' => $description],
            ['value' => 'beta', 'label' => 'beta', 'description' => $description],
        ]);

        $list->setSelectedIndex(0);
        $selected = $list->render(new RenderContext(60, 24));
        $list->setSelectedIndex(1);
        $unselected = $list->render(new RenderContext(60, 24));

        $this->assertStringContainsString('→ alpha', AnsiUtils::stripAnsiCodes($selected[0]));
        $this->assertStringContainsString('  alpha', AnsiUtils::stripAnsiCodes($unselected[0]));
        $this->assertSame(
            AnsiUtils::visibleWidth($unselected[0]),
            AnsiUtils::visibleWidth($selected[0]),
            'The selected first row is budgeted to the same width as the unselected first row of the same item.',
        );
    }

    public function testSelectedRowWithoutDescriptionGetsTheSameWidthBudget()
    {
        $label = str_repeat('L', 120);
        $list = new SelectListWidget([
            ['value' => 'alpha', 'label' => $label],
            ['value' => 'beta', 'label' => $label],
        ]);

        $list->setSelectedIndex(0);
        $selected = $list->render(new RenderContext(60, 24));
        $list->setSelectedIndex(1);
        $unselected = $list->render(new RenderContext(60, 24));

        $this->assertStringStartsWith('→ ', AnsiUtils::stripAnsiCodes($selected[0]));
        $this->assertStringStartsWith('  ', AnsiUtils::stripAnsiCodes($unselected[0]));
        $this->assertSame(
            AnsiUtils::visibleWidth($unselected[0]),
            AnsiUtils::visibleWidth($selected[0]),
            'The selected first row is budgeted to the same width as the unselected first row of the same item.',
        );
    }

    public function testKeybindingLabelsOmitToggleWithoutMultiselect()
    {
        $labels = $this->createTestList()->getKeybindingLabels();

        $this->assertArrayNotHasKey('choice_toggle', $labels);
        $this->assertSame('Select', $labels['select_confirm']);
    }

    public function testKeybindingLabelsIncludeToggleWithMultiselect()
    {
        $labels = $this->createTestList(multiselect: true)->getKeybindingLabels();

        $this->assertSame('Toggle', $labels['choice_toggle']);
    }

    public function testExplicitKeybindingLabelsWin()
    {
        $labels = $this->createTestList()->setKeybindingLabels(['select_confirm' => 'OK'])->getKeybindingLabels();

        $this->assertSame(['select_confirm' => 'OK'], $labels);
    }

    /**
     * @return array{SelectListWidget, Tui}
     */
    private function createTestListWithTui(bool $multiselect = false): array
    {
        $terminal = new VirtualTerminal(80, 24);
        $tui = new Tui(terminal: $terminal);
        $list = $this->createTestList($multiselect);
        $tui->add($list);

        return [$list, $tui];
    }
}
