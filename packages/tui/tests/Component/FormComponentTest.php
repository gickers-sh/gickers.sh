<?php

declare(strict_types=1);

namespace CodesSh\Tui\Tests\Component;

use CodesSh\Tui\Component\Field\TextAreaField;
use CodesSh\Tui\Component\Field\TextInputField;
use CodesSh\Tui\Component\Field\ToggleField;
use CodesSh\Tui\Component\FormComponent;
use CodesSh\Tui\Event\KeyMsg;
use PHPUnit\Framework\TestCase;

final class FormComponentTest extends TestCase
{
    public function testFormFieldsNavigationAndData(): void
    {
        $nameField = new TextInputField('name', 'Repository Name', 'test-repo');
        $descField = new TextAreaField('desc', 'Description', "Line 1\nLine 2");
        $publicField = new ToggleField('public', 'Public repo', true);

        $form = new FormComponent('Create Repository', [$nameField, $descField, $publicField], 'Enter details');

        $this->assertSame(0, $form->getFocusedIndex());
        $this->assertSame('test-repo', $nameField->getValue());
        $this->assertTrue($publicField->getValue());

        // Test down arrow / tab advances focus
        $form->update(new KeyMsg(KeyMsg::TAB));
        $this->assertSame(1, $form->getFocusedIndex());

        $form->update(new KeyMsg(KeyMsg::DOWN));
        $this->assertSame(2, $form->getFocusedIndex());

        // Toggle field with space
        $form->update(new KeyMsg(' '));
        $this->assertFalse($publicField->getValue());

        // Up arrow / shift+tab backwards focus
        $form->update(new KeyMsg(KeyMsg::UP));
        $this->assertSame(1, $form->getFocusedIndex());

        $form->update(new KeyMsg(KeyMsg::BACKTAB));
        $this->assertSame(0, $form->getFocusedIndex());

        // Typing in text field
        $form->update(new KeyMsg(KeyMsg::RIGHT));
        $form->update(new KeyMsg('x', 'x'));
        $this->assertSame('test-repox', $nameField->getValue());

        // Backspace
        $form->update(new KeyMsg(KeyMsg::BACKSPACE));
        $this->assertSame('test-repo', $nameField->getValue());

        // Check getData()
        $data = $form->getData();
        $this->assertSame('test-repo', $data['name']);
        $this->assertFalse($data['public']);

        // Check shortcuts ctrl+s and esc
        $resSubmit = $form->update(new KeyMsg('ctrl+s', '', false, true));
        $this->assertSame('submit', $resSubmit);

        $resCancel = $form->update(new KeyMsg(KeyMsg::ESC));
        $this->assertSame('cancel', $resCancel);
    }

    public function testFormViewRendersModal(): void
    {
        $field = new TextInputField('title', 'Title', 'Hello');
        $form = new FormComponent('Edit Title', [$field]);

        $rendered = $form->view(80, 24);
        $this->assertStringContainsString('Edit Title', $rendered);
        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('ctrl+s submit', $rendered);
    }
}
