import {Plugin} from '@ckeditor/ckeditor5-core';

export class CkContentClass extends Plugin {
  static pluginName = 'CkContentClass';

  init() {
    const editor = this.editor;
    const config = editor.config.get('ckContentClass');

    //console.log('CkContentClass plugin loaded, config:', config);

    if (config) {
      // Wait for the editor to be fully ready
      editor.on('ready', () => {
        //console.log('Editor ready, trying to add class');
        this.addClassToContentArea(config);
      });

      // Also try when the view is rendered
      editor.editing.view.on('render', () => {
        this.addClassToContentArea(config);
      });
    }
  }

  addClassToContentArea(className) {
    const editor = this.editor;
    const editingView = editor.editing.view;
    const editableElement = editingView.getDomRoot();

    if (editableElement) {
      editableElement.classList.add(className);
      //console.log('Added class', className, 'to editing root');
    }
  }
}
