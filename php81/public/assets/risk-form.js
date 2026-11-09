(() => {
    'use strict';

    document.querySelectorAll('[data-risk-form]').forEach((form) => {
        const directorate = form.querySelector('[data-risk-directorate]');
        const project = form.querySelector('[data-risk-project]');
        if (!directorate || !project) return;

        const filterProjects = () => {
            const directorateId = directorate.value;
            let selectedVisible = true;
            Array.from(project.options).forEach((option) => {
                const optionDirectorate = option.dataset.directorate || '';
                const visible = option.value === '' || optionDirectorate === directorateId;
                option.hidden = !visible;
                option.disabled = !visible;
                if (option.selected && !visible) selectedVisible = false;
            });
            if (!selectedVisible) project.value = '';
        };

        directorate.addEventListener('change', filterProjects);
        filterProjects();
    });
})();
