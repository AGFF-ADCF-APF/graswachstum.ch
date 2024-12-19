import ClassicEditor from 'ClassicEditor';

window.nextgenEditor.exports = {};
const { jQuery } = window;

async function bindField(field) {
  // eslint-disable-next-line no-underscore-dangle
  if (window.nextgenEditor.editors.includes(field) || field.__NEXTGEN_INITIALIZING) {
    return;
  }

  // eslint-disable-next-line no-underscore-dangle
  field.__NEXTGEN_INITIALIZING = true;
  const textarea = field.querySelector('textarea');
  const { runHook, runHookSync } = window.nextgenEditor;
  let { options } = JSON.parse(textarea.dataset.nextgenEditor);

  await runHook('hookInit');

  options = await runHook('hookOptions', [], options);

  (options.nextgenEditor.typing.transformations.extra || []).forEach((value, index) => {
    if (Array.isArray(value.to)) {
      value.from = new RegExp(value.from);
      options.nextgenEditor.typing.transformations.extra[index] = value;
    }
  });

  const initialHTML = await runHook('hookMarkdowntoHTML', [options], textarea.value);

  options.nextgenEditor.extraPlugins = Object.values(window.nextgenEditor.plugins);

  const providers = [];
  options.nextgenEditor.mediaEmbed.extraProviders.forEach((provider) => {
    const regex = provider.url.match(/^\/(.*?)\/([gim]*)$/);
    providers.push({
      name: provider.name,
      url: regex ? new RegExp(regex[1], regex[2]) : new RegExp(provider.url),
      // eslint-disable-next-line no-unused-vars
      html: (match) => provider.html // eslint-disable-next-line  no-template-curly-in-string
        .replace('${match[0]}', match[0]) // eslint-disable-next-line  no-template-curly-in-string
        .replace('${match[1]}', match[1]) // eslint-disable-next-line  no-template-curly-in-string
        .replace('${match[2]}', match[2]), // eslint-disable-next-line  no-template-curly-in-string
    });
  });

  options.nextgenEditor.mediaEmbed.extraProviders = providers;

  await runHook('hookBeforeEditorInit', [options]);

  ClassicEditor
    .create(textarea, {
      ...options.nextgenEditor,
      initialData: initialHTML,
    })
    .then(async (editor) => {
      field.classList.remove('loading');
      window.nextgenEditor.editors.push(editor);

      // Backward compatibility pre multiple-instances support: editor = first editor found
      if (!window.nextgenEditor.editor) {
        window.nextgenEditor.editor = editor;
      }

      await runHook('hookAfterEditorInit', [options, editor]);

      editor.model.document.on('change:data', async () => {
        await runHook('hookOnChange', [options, editor]);
      });

      textarea.form.addEventListener('submit', () => {
        const sourceCode = document.querySelector('.ck-source-editing-area');
        const value = sourceCode ? sourceCode.dataset.value : textarea.value;
        textarea.value = runHookSync('hookHTMLtoMarkdown', [options, editor], value);
      });
    })
    .catch((err) => {
      // eslint-disable-next-line no-console
      console.error(err);
    });
}

const registerFields = () => {
  const fields = document.querySelectorAll('.nextgen-editor-form.loading');
  window.nextgenEditor.editors = [];

  if (!fields.length) {
    return;
  }

  fields.forEach((field) => bindField(field));
};

if (document.readyState !== 'complete') {
  document.addEventListener('readystatechange', () => {
    if (document.readyState === 'complete') {
      registerFields();

      jQuery('body').on('mutation._grav', async (event, target) => {
        const editor = target.querySelector('.nextgen-editor-form.loading');
        if (editor) {
          await bindField(editor);
        }
      });
    }
  });
} else {
  registerFields();
}
