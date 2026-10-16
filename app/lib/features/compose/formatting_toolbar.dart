import 'package:flutter/material.dart';

import 'rich_text_controller.dart';

/// Bold, italic, underline, strike, lists and clear, for a [RichTextController].
/// Buttons show whether the format applies to the selection or what is typed next.
class FormattingToolbar extends StatelessWidget {
  const FormattingToolbar({super.key, required this.controller});
  final RichTextController controller;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        Widget mark(int m, IconData icon, String tip) => _ToolButton(
          icon: icon,
          tooltip: tip,
          selected: controller.isActive(m),
          onPressed: () => controller.toggle(m),
        );
        Widget list(ListKind k, IconData icon, String tip) => _ToolButton(
          icon: icon,
          tooltip: tip,
          selected: controller.activeList == k,
          onPressed: () => controller.toggleList(k),
        );
        return Container(
          decoration: BoxDecoration(
            color: scheme.surfaceContainerHigh,
            borderRadius: BorderRadius.circular(16),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
          // Taps here count as taps in the text field (it keeps focus and the
          // keyboard), and the buttons never take focus themselves.
          child: TextFieldTapRegion(
            child: ExcludeFocus(
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    mark(Mark.bold, Icons.format_bold, 'Bold'),
                    mark(Mark.italic, Icons.format_italic, 'Italic'),
                    mark(Mark.underline, Icons.format_underlined, 'Underline'),
                    mark(
                      Mark.strike,
                      Icons.format_strikethrough,
                      'Strikethrough',
                    ),
                    const _Divider(),
                    list(
                      ListKind.bullet,
                      Icons.format_list_bulleted,
                      'Bulleted list',
                    ),
                    list(
                      ListKind.numbered,
                      Icons.format_list_numbered,
                      'Numbered list',
                    ),
                    const _Divider(),
                    IconButton(
                      tooltip: 'Clear formatting',
                      icon: const Icon(Icons.format_clear),
                      onPressed: controller.clearFormatting,
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _ToolButton extends StatelessWidget {
  const _ToolButton({
    required this.icon,
    required this.tooltip,
    required this.selected,
    required this.onPressed,
  });
  final IconData icon;
  final String tooltip;
  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: tooltip,
    isSelected: selected,
    icon: Icon(icon),
    style: IconButton.styleFrom(
      backgroundColor: selected
          ? Theme.of(context).colorScheme.secondaryContainer
          : null,
    ),
    onPressed: onPressed,
  );
}

class _Divider extends StatelessWidget {
  const _Divider();

  @override
  Widget build(BuildContext context) => Container(
    width: 1,
    height: 24,
    margin: const EdgeInsets.symmetric(horizontal: 4),
    color: Theme.of(context).colorScheme.outlineVariant,
  );
}
