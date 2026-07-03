export default function ErrorMessage({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p className="mt-1.5 text-sm text-red-600" role="alert">
            {message}
        </p>
    );
}
