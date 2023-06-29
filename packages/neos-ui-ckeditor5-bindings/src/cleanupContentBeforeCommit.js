// We remove opening and closing span tags that are produced by the inlineMode plugin
/** @param {String} content */
export const cleanupContentBeforeCommit = content => {
    // TODO: remove when this is fixed: https://github.com/ckeditor/ckeditor5/issues/401
    if (content.match(/^<([a-z][a-z0-9]*)\b[^>]*>&nbsp;<\/\1>$/)) {
        return '';
    }
    return content;
};
