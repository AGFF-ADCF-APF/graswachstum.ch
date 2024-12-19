import { convertImage, setAttributes } from '../utils/utils';

const Command = window.nextgenEditor.classes.core.command.class;

export default class GravImageSrcCommand extends Command {
  refresh() {
    const element = this.editor.model.document.selection.getSelectedElement();
    const imageUtils = this.editor.plugins.get('ImageUtils');

    this.isEnabled = imageUtils.isImage(element);

    if (imageUtils.isImage(element) && element.hasAttribute('data-src')) {
      this.value = decodeURIComponent(element.getAttribute('data-src'));
    } else {
      this.value = false;
    }
  }

  async execute(options) {
    const modelImage = this.editor.model.document.selection.getSelectedElement();

    const viewImage = this.editor.editing.mapper.toViewElement(modelImage);
    const domImage = this.editor.editing.view.domConverter.viewToDom(viewImage).querySelector('img');

    domImage.setAttribute('data-src', options.newValue);
    const newDomImage = await convertImage(domImage);

    if (newDomImage) {
      setAttributes(this.editor, newDomImage, modelImage);
    }
  }
}
