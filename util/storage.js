// Database selection: MySQL only.

function has(...names) {
    return names.every((name) => !!process.env[name]);
}

exports.resolve = function () {
    if (has('DATABASE', 'USERNAME', 'PASSWORD', 'HOST')) {
        return { mode: 'mysql' };
    }

    return { mode: 'none' };
};
