export const imageRegexp = /\.(jpg|jpeg|png|gif|bmp|svg|webp)/;

export function setAttributes(editor, srcElement, dstElement) {
  editor.model.change((writer) => {
    writer.setAttribute('src', srcElement.getAttribute('src'), dstElement);
    writer.setAttribute('srcset', { data: srcElement.getAttribute('srcset') }, dstElement);
    writer.setAttribute('data-style', srcElement.getAttribute('style'), dstElement);
    writer.setAttribute('data-src', srcElement.getAttribute('data-src'), dstElement);
    writer.setAttribute('sizes', srcElement.getAttribute('sizes'), dstElement);
  });
}

export function convertImageParseResult(resHtml) {
  const domImage = new DOMParser().parseFromString(resHtml, 'text/html');

  // replace style attribute by data-style
  if (domImage.querySelector('img[style]')) {
    const imgElm = domImage.querySelector('img[style]');
    imgElm.setAttribute('data-style', imgElm.getAttribute('style'));
    imgElm.removeAttribute('style');
  }

  // handle lightbox images
  if (domImage.querySelector('a[data-src][rel="lightbox"]>img')) {
    const aElm = domImage.querySelector('a[data-src][rel="lightbox"]');
    const imgElm = domImage.querySelector('a[data-src][rel="lightbox"]>img');

    imgElm.setAttribute('data-src', aElm.getAttribute('data-src'));
    aElm.parentNode.replaceChild(imgElm, aElm);

    return domImage.body.innerHTML;
  }

  // handle images with incorrect source
  if (domImage.querySelector('a[data-src]>img')) {
    const aElm = domImage.querySelector('a[data-src]');
    const imgElm = domImage.querySelector('a[data-src]>img');

    imgElm.setAttribute('data-src', aElm.getAttribute('data-src'));
    aElm.parentNode.replaceChild(imgElm, aElm);

    return domImage.body.innerHTML;
  }

  return domImage.body.innerHTML;
}

export async function convertImage(domImage) {
  const body = new FormData();

  const imgSrc = await window.nextgenEditor.runHook(
    'hookImageBeforeUrlConvert',
    [],
    domImage.getAttribute('data-src'),
  );
  domImage.setAttribute('src', imgSrc);

  const imgText = domImage.outerHTML;

  body.append('route', btoa(`/${window.GravAdmin.config.route}`));

  body.append(
    'data',
    JSON.stringify({
      img: [imgText],
      a: [],
    }),
  );

  const res = await fetch(
    `${window.GravAdmin.config.current_url}/action:convertUrls/admin-nonce:${window.GravAdmin.config.admin_nonce}`,
    { body, method: 'POST' },
  ).then(resp => (resp.ok ? resp.json() : null));

  if (res && res.status === 'success') {
    const newImgText = convertImageParseResult(res.data.images[imgText]);
    const newDomImage = new DOMParser().parseFromString(newImgText, 'text/html').body.firstChild;

    const newImgSrc = await window.nextgenEditor.runHook(
      'hookImageAfterUrlConvert',
      [],
      newDomImage.getAttribute('data-src'),
    );
    newDomImage.setAttribute('data-src', newImgSrc);

    return newDomImage;
  }

  return null;
}

export async function insertImage(editor, imgSrc) {
  const newImgSrc = await window.nextgenEditor.runHook('hookImageBeforeUrlConvert', [], imgSrc);
  const imgText = `<img data-src='${newImgSrc}'>`;

  const domImage = new DOMParser().parseFromString(imgText, 'text/html').body.firstChild;
  const newDomImage = await convertImage(domImage);

  if (newDomImage) {
    editor.model.change(modelWriter => {
      const modelImage = modelWriter.createElement('imageBlock');
      setAttributes(editor, newDomImage, modelImage);

      editor.model.insertContent(modelImage, editor.model.document.selection.focus);
    });
  }
}

export async function insertLink(editor, linkHref) {
  const newLinkHref = await window.nextgenEditor.runHook('hookImageBeforeUrlConvert', [], linkHref);

  editor.model.change(modelWriter => {
    const link = modelWriter.createText(linkHref);
    modelWriter.setAttribute('linkHref', linkHref, link);
    modelWriter.setAttribute('data-href', newLinkHref, link);

    editor.model.insertContent(link, editor.model.document.selection);
  });
}

export async function insertMedia(editor, uri) {
  const isImage = imageRegexp.test(uri);
  return isImage ? insertImage(editor, uri) : insertLink(editor, uri);
}

window.nextgenEditor.exports.insertMedia = insertMedia;
