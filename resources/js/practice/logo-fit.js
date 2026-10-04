export function fitLogo(width, height, maxWidth, maxHeight) {
    if (!(width > 0 && height > 0)) return { width: maxWidth, height: maxHeight };
    const scale = Math.min(maxWidth / width, maxHeight / height);
    return { width: width * scale, height: height * scale };
}
