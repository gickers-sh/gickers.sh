<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

use App\UI\Tui\Format\Ansi;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The standard page frame, with a two-column side margin (`.chrome`):
 *
 *     top margin row
 *     header        (width-aware painter, {@see setHeader()})
 *     tab bar       (optional {@see TabBarWidget})
 *     body          (expands vertically)
 *     status        (width-aware painter, replaced by the flash message when set)
 */
final class ChromeWidget extends ContainerWidget
{
    private readonly LinesWidget $margin;
    private readonly LinesWidget $header;
    private readonly ContainerWidget $tabSlot;
    private readonly ContainerWidget $bodySlot;
    private readonly LinesWidget $status;
    private ?string $flash = null;
    /** @var \Closure(int): list<string> */
    private \Closure $statusPainter;

    public function __construct(?TabBarWidget $tabs, AbstractWidget $body)
    {
        $this->addStyleClass('chrome');
        $this->expandVertically(true);
        $this->statusPainter = static fn (int $cols): array => [];
        $this->margin = new LinesWidget(['']);
        $this->header = new LinesWidget();
        $this->tabSlot = new ContainerWidget();
        $this->bodySlot = new ContainerWidget();
        $this->bodySlot->expandVertically(true);
        $this->status = new LinesWidget(function (int $cols): array {
            if (null !== $this->flash) {
                return ["\e[7m".Ansi::fit(' '.$this->flash, $cols)."\e[0m"];
            }

            return ($this->statusPainter)($cols);
        });
        $this->add($this->margin);
        $this->add($this->header);
        $this->add($this->tabSlot);
        $this->add($this->bodySlot);
        $this->add($this->status);
        $this->setTabs($tabs);
        $this->setBody($body);
    }

    /**
     * Hide the top margin row (full-screen views).
     */
    public function setTopMargin(bool $show): static
    {
        $this->margin->setPainter($show ? [''] : []);

        return $this;
    }

    /**
     * @param (\Closure(int $cols): list<string>)|list<string> $painter
     */
    public function setHeader(\Closure|array $painter): static
    {
        $this->header->setPainter($painter);

        return $this;
    }

    public function setTabs(?AbstractWidget $tabs): static
    {
        $current = $this->tabSlot->all()[0] ?? null;
        if ($current === $tabs) {
            return $this;
        }
        $this->tabSlot->clear();
        if (null !== $tabs) {
            $this->tabSlot->add($tabs);
        }

        return $this;
    }

    public function setBody(AbstractWidget $body): static
    {
        $current = $this->bodySlot->all()[0] ?? null;
        if ($current === $body) {
            return $this;
        }
        $this->bodySlot->clear();
        $this->bodySlot->add($body);

        return $this;
    }

    public function body(): ?AbstractWidget
    {
        return $this->bodySlot->all()[0] ?? null;
    }

    /**
     * @param (\Closure(int $cols): list<string>)|list<string> $painter
     */
    public function setStatus(\Closure|array $painter): static
    {
        $this->statusPainter = $painter instanceof \Closure ? $painter : static fn (int $cols): array => $painter;
        $this->status->refresh();

        return $this;
    }

    /**
     * Show $message in place of the status bar (null restores the status bar).
     */
    public function setFlash(?string $message): static
    {
        if ($message !== $this->flash) {
            $this->flash = $message;
            $this->status->refresh();
        }

        return $this;
    }

    public function flash(): ?string
    {
        return $this->flash;
    }

    /**
     * Re-run the header and status painters (their inputs changed).
     */
    public function refresh(): void
    {
        $this->header->refresh();
        $this->status->refresh();
    }

    /**
     * Header and status read mutable view state (ref, flash, selection): repaint every frame.
     */
    public function beforeRender(): void
    {
        $this->refresh();
    }
}
