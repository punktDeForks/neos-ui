import Plugin from '@ckeditor/ckeditor5-core/src/plugin';

/**
 * Legacy HACK -> our previous "inlineMode" the `autoparagraph: false` mode for backwards compatibility
 * @deprecated in favour of the serious "inlineMode"
 */
export default class DisabledAutoparagraphMode extends Plugin {
    static get pluginName() {
        return 'DisabledAutoparagraphMode';
    }

    init() {
        const {editor} = this;

        // we map paragraph model into plain <span> element in edit mode
        editor.conversion.for('editingDowncast').elementToElement({model: 'paragraph', view: 'span', converterPriority: 'high'});

        // to avoid having a wrapping "span" tag, we will convert the outmost 'paragraph' and strip the custom tag 'neos-inline-wrapper'
        // in a hacky cleanup in cleanupContentBeforeCommit
        // see https://neos-project.slack.com/archives/C07QEQ1U2/p1687952441254759 - i could find a better solution
        editor.conversion.for('dataDowncast').elementToElement({model: 'paragraph', view: (modelElement, viewWriter) => {
            const parentIsRoot = modelElement.parent.is('$root');
            const hasAttributes = [...modelElement.getAttributes()].length !== 0;
            if (!parentIsRoot || hasAttributes) {
                return viewWriter.createContainerElement('span');
            }
            return viewWriter.createContainerElement('neos-inline-wrapper');
        }, converterPriority: 'high'});

        // we redefine enter key to create soft breaks (<br>) instead of new paragraphs
        editor.editing.view.document.on('enter', (evt, data) => {
            editor.execute('shiftEnter');
            data.preventDefault();
            evt.stop();
            editor.editing.view.scrollToTheSelection();
        }, {priority: 'high'});
    }
}
