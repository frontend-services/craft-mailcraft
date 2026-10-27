/**
 * MailCraft — Twig placeholder highlighting for CKEditor 5.
 */
import { Plugin } from 'ckeditor5';

const GROUP = 'mailcraftTwig';
const PATTERN = /\{\{[\s\S]*?\}\}|\{%[\s\S]*?%\}|\{#[\s\S]*?#\}/g;

export class MailCraftTwigHighlight extends Plugin {
  static get pluginName() {
    return 'MailCraftTwigHighlight';
  }

  init() {
    const editor = this.editor;

    editor.conversion.for('editingDowncast').markerToHighlight({
      model: GROUP,
      view: (data) => {
        const kind = String(data.markerName).split(':')[1] || 'var';
        return {
          classes: ['mailcraft-twig', 'mailcraft-twig--' + kind],
          priority: 5,
        };
      },
    });

    this.listenTo(editor, 'ready', () => this._refresh());
    this.listenTo(editor.model.document, 'change:data', () => this._refresh());
  }

  _refresh() {
    const model = this.editor.model;
    const root = model.document.getRoot();
    if (!root) {
      return;
    }

    const wanted = new Map();
    let index = 0;
    for (const block of this._textBlocks(root)) {
      for (const match of this._matches(block)) {
        const name = GROUP + ':' + match.kind + ':' + index++;
        wanted.set(name, model.createRange(
          model.createPositionAt(block, match.start),
          model.createPositionAt(block, match.end)
        ));
      }
    }

    model.change((writer) => {
      for (const marker of Array.from(model.markers.getMarkersGroup(GROUP))) {
        const range = wanted.get(marker.name);
        if (!range) {
          writer.removeMarker(marker);
        } else {
          if (!marker.getRange().isEqual(range)) {
            writer.updateMarker(marker, { range });
          }
          wanted.delete(marker.name);
        }
      }
      for (const [name, range] of wanted) {
        writer.addMarker(name, { range, usingOperation: false, affectsData: false });
      }
    });
  }

  *_textBlocks(root) {
    const stack = [root];
    while (stack.length) {
      const element = stack.pop();
      let hasText = false;
      for (const child of element.getChildren()) {
        if (child.is('$text')) {
          hasText = true;
        } else if (child.is('element')) {
          stack.push(child);
        }
      }
      if (hasText) {
        yield element;
      }
    }
  }

  _matches(block) {
    let text = '';
    for (const child of block.getChildren()) {
      text += child.is('$text') ? child.data : '\u0000'.repeat(child.offsetSize);
    }

    const matches = [];
    PATTERN.lastIndex = 0;
    let m;
    while ((m = PATTERN.exec(text)) !== null) {
      if (m[0].indexOf('\u0000') !== -1) {
        continue; // delimiter spans an inline element; leave it alone
      }
      const kind = m[0][1] === '{' ? 'var' : (m[0][1] === '%' ? 'tag' : 'comment');
      matches.push({ kind, start: m.index, end: m.index + m[0].length });
    }
    return matches;
  }
}

export default MailCraftTwigHighlight;
